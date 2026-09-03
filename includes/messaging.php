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

function twilio_client_credentials(array $config): array
{
    $method=($config['auth_method']??'auth_token')==='api_key'?'api_key':'auth_token';
    $accountSid=trim((string)($config['account_sid']??''));
    $region=strtolower(trim((string)($config['region']??'us1'))) ?: 'us1';
    if($method==='api_key'){
        return ['method'=>$method,'username'=>trim((string)($config['api_key_sid']??'')),'password'=>(string)($config['api_key_secret']??''),'account_sid'=>$accountSid,'region'=>$region];
    }
    return ['method'=>$method,'username'=>$accountSid,'password'=>(string)($config['auth_token']??''),'account_sid'=>null,'region'=>$region];
}

function twilio_sms_sender_options(array $config): array
{
    if(!empty($config['sms_from']))return ['from'=>(string)$config['sms_from']];
    if(!empty($config['messaging_service_sid']))return ['messagingServiceSid'=>(string)$config['messaging_service_sid']];
    return [];
}

function messaging_slip_filename(int $orderId,?string $token=null): string
{
    return 'slip_'.$orderId.'_'.($token??bin2hex(random_bytes(12))).'.jpg';
}

function messaging_generate_slip_image(int $orderId,int $vendorId): string
{
    $filename=messaging_slip_filename($orderId);
    $output=dirname(__DIR__).'/images/'.$filename;
    $baseUrl=rtrim((string)(getenv('APP_URL')?:'https://coffee.tatu.co.za'),'/');
    $expires=time()+300;
    $signingKey=(string)getenv('APP_KEY');
    if($signingKey==='')throw new RuntimeException('Receipt signing key is not configured.');
    $signature=hash_hmac('sha256',$orderId.'|'.$vendorId.'|'.$expires,$signingKey);
    $url=$baseUrl.'/admin/slip_image.php?'.http_build_query(['id'=>$orderId,'rid'=>$vendorId,'expires'=>$expires,'signature'=>$signature]);
    $process=proc_open(['/usr/bin/wkhtmltoimage','--quiet','--width','300',$url,$output],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Could not start receipt image generator.');
    $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exitCode=proc_close($process);
    if($exitCode!==0 || !is_file($output) || filesize($output)===0){
        $detail=$stderr!==''?trim($stderr):trim($stdout);
        throw new RuntimeException('Could not generate receipt image'.($detail!==''?': '.$detail:'.'));
    }
    return $filename;
}

function messaging_whatsapp_content_variables(array $order,int $orderId,string $slipFilename): string
{
    return json_encode(['1'=>(string)$order['name'],'2'=>(string)$orderId,'3'=>$slipFilename],JSON_THROW_ON_ERROR);
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
    $credentials=twilio_client_credentials($config);
    if($credentials['username']===''||$credentials['password']===''||($credentials['method']==='api_key'&&$credentials['account_sid']===''))throw new RuntimeException('Twilio authentication is incomplete.');
    $region=$credentials['region']==='us1'?null:$credentials['region'];
    $client=new \Twilio\Rest\Client($credentials['username'],$credentials['password'],$credentials['account_sid'],$region);
    $body=sprintf('%s: Order #%d for %s is ready. Total R%s.',(string)$order['vendor_name'],$orderId,(string)$order['name'],number_format((float)$order['total'],2,'.',''));
    $errors=[];
    foreach(messaging_channels($config) as $channel){
        try{
            if($channel==='sms'){
                // Prefer the vendor's explicit number. A Messaging Service may be
                // configured for another channel or have an empty SMS sender pool.
                $payload=['body'=>$body]+twilio_sms_sender_options($config);
                if(count($payload)===1)throw new RuntimeException('SMS sender is not configured.');
                $message=$client->messages->create($to,$payload);
            }else{
                $from=(string)($config['whatsapp_from']??'');if($from==='')throw new RuntimeException('WhatsApp sender is not configured.');
                $waTo='whatsapp:'.$to;$payload=['from'=>str_starts_with($from,'whatsapp:')?$from:'whatsapp:'.$from];
                if(!empty($config['content_sid_order_ready'])){
                    $slipFilename=messaging_generate_slip_image($orderId,$vendorId);
                    $payload['contentSid']=$config['content_sid_order_ready'];
                    $payload['contentVariables']=messaging_whatsapp_content_variables($order,$orderId,$slipFilename);
                }else{$payload['body']=$body;}
                $message=$client->messages->create($waTo,$payload);
            }
            message_delivery_log($pdo,$vendorId,$orderId,$channel,$to,'queued',(string)$message->sid);
            return ['sent'=>true,'channel'=>$channel,'sid'=>(string)$message->sid];
        }catch(Throwable $e){$errors[]=$channel.': '.$e->getMessage();message_delivery_log($pdo,$vendorId,$orderId,$channel,$to,'failed',null,$e->getMessage());}
    }
    error_log('Order-ready messaging failed for order '.$orderId.': '.implode('; ',$errors));
    return ['sent'=>false,'reason'=>'all_channels_failed'];
}
