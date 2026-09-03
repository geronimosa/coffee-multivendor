<?php
declare(strict_types=1);

require_once __DIR__ . '/integrations.php';

function messaging_phone_e164(string $raw): ?string
{
    $digits=preg_replace('/\D+/', '', $raw) ?: '';
    if(strlen($digits)===10 && $digits[0]==='0')$digits='27'.substr($digits,1);
    if(strlen($digits)<10 || strlen($digits)>15)return null;
    return '+'.$digits;
}

function messaging_channels(array $config): array
{
    $primary=$config['primary_channel']??(!empty($config['whatsapp_from'])?'whatsapp':'sms');
    $primary=in_array($primary,['sms','whatsapp'],true)?$primary:'sms';
    $fallback=$config['fallback_channel']??'';
    return ($fallback && $fallback!==$primary && in_array($fallback,['sms','whatsapp'],true))?[$primary,$fallback]:[$primary];
}

function message_delivery_log(PDO $pdo,int $vendorId,?int $orderId,string $channel,string $recipient,string $status,?string $sid=null,?string $error=null): void
{
    $pdo->prepare('INSERT INTO message_deliveries(vendor_id,order_id,channel,recipient,event_type,provider_message_sid,status,error_message) VALUES(?,?,?,?,\'order_ready\',?,?,?)')->execute([$vendorId,$orderId,$channel,$recipient,$sid,$status,$error?mb_substr($error,0,255):null]);
}

function send_order_ready_message(PDO $pdo,int $vendorId,int $orderId): array
{
    $integration=integration_for_vendor($pdo,$vendorId,'twilio');
    if(!$integration || empty($integration['enabled']))return ['sent'=>false,'reason'=>'disabled'];
    $config=integration_config($integration);
    $stmt=$pdo->prepare('SELECT o.id,o.name,o.phone,o.total,r.name vendor_name FROM orders o JOIN restaurants r ON r.id=o.restaurant_id WHERE o.id=? AND o.restaurant_id=? LIMIT 1');$stmt->execute([$orderId,$vendorId]);$order=$stmt->fetch();
    if(!$order || !($to=messaging_phone_e164((string)$order['phone'])))return ['sent'=>false,'reason'=>'invalid_phone'];
    if(!class_exists('Twilio\\Rest\\Client')){
        $autoload=dirname(__DIR__).'/vendor/autoload.php';
        if(!is_file($autoload))throw new RuntimeException('Twilio SDK is not installed.');
        require_once $autoload;
    }
    $client=new \Twilio\Rest\Client((string)($config['account_sid']??''),(string)($config['auth_token']??''));
    $body=sprintf('%s: Order #%d for %s is ready. Total R%s.',(string)$order['vendor_name'],$orderId,(string)$order['name'],number_format((float)$order['total'],2,'.',''));
    $errors=[];
    foreach(messaging_channels($config) as $channel){
        try{
            if($channel==='sms'){
                $payload=['body'=>$body];
                if(!empty($config['messaging_service_sid']))$payload['messagingServiceSid']=$config['messaging_service_sid'];
                else $payload['from']=$config['sms_from']??'';
                if(empty($payload['from'])&&empty($payload['messagingServiceSid']))throw new RuntimeException('SMS sender is not configured.');
                $message=$client->messages->create($to,$payload);
            }else{
                $from=(string)($config['whatsapp_from']??'');if($from==='')throw new RuntimeException('WhatsApp sender is not configured.');
                $waTo='whatsapp:'.$to;$payload=['from'=>str_starts_with($from,'whatsapp:')?$from:'whatsapp:'.$from];
                if(!empty($config['content_sid_order_ready'])){$payload['contentSid']=$config['content_sid_order_ready'];$payload['contentVariables']=json_encode(['1'=>$order['name'],'2'=>(string)$orderId,'3'=>$body],JSON_THROW_ON_ERROR);}else{$payload['body']=$body;}
                $message=$client->messages->create($waTo,$payload);
            }
            message_delivery_log($pdo,$vendorId,$orderId,$channel,$to,'queued',(string)$message->sid);
            return ['sent'=>true,'channel'=>$channel,'sid'=>(string)$message->sid];
        }catch(Throwable $e){$errors[]=$channel.': '.$e->getMessage();message_delivery_log($pdo,$vendorId,$orderId,$channel,$to,'failed',null,$e->getMessage());}
    }
    error_log('Order-ready messaging failed for order '.$orderId.': '.implode('; ',$errors));
    return ['sent'=>false,'reason'=>'all_channels_failed'];
}
