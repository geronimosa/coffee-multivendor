<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/messaging.php';

$expect = static function ($actual, $expected, string $message): void {
    if ($actual !== $expected) throw new RuntimeException($message);
};

$expect(messaging_phone_e164('082 440 3123'), '+27824403123', 'South African mobile formatting failed.');
$expect(messaging_phone_e164('+27 82 440 3123'), '+27824403123', 'E.164 formatting failed.');
$expect(messaging_phone_e164('123'), null, 'Invalid phone number was accepted.');
$expect(messaging_channels(['primary_channel'=>'sms','fallback_channel'=>'whatsapp']), ['sms','whatsapp'], 'Fallback ordering failed.');
$expect(messaging_channels(['primary_channel'=>'sms','fallback_channel'=>'sms']), ['sms'], 'Duplicate fallback was retained.');
$expect(messaging_channels(['whatsapp_from'=>'whatsapp:+27000000000']), ['whatsapp'], 'Legacy WhatsApp configuration was not preserved.');
$expect(twilio_client_credentials(['auth_method'=>'api_key','account_sid'=>'AC123','api_key_sid'=>'SK123','api_key_secret'=>'secret','region'=>'us1']), ['method'=>'api_key','username'=>'SK123','password'=>'secret','account_sid'=>'AC123','region'=>'us1'], 'API Key credentials were not constructed correctly.');
$expect(twilio_client_credentials(['account_sid'=>'AC123','auth_token'=>'token']), ['method'=>'auth_token','username'=>'AC123','password'=>'token','account_sid'=>null,'region'=>'us1'], 'Legacy Auth Token credentials were not preserved.');
$expect(twilio_sms_sender_options(['sms_from'=>'+17408297187','messaging_service_sid'=>'MG123']), ['from'=>'+17408297187'], 'Explicit SMS sender did not take precedence.');
$expect(twilio_sms_sender_options(['messaging_service_sid'=>'MG123']), ['messagingServiceSid'=>'MG123'], 'Messaging Service fallback failed.');
$expect(messaging_slip_filename(42,'abc123'), 'slip_42_abc123.jpg', 'Receipt filename was not constructed correctly.');
$expect(messaging_whatsapp_content_variables(['name'=>'Steve'],42,'slip_42_abc123.jpg'), '{"1":"Steve","2":"42","3":"slip_42_abc123.jpg"}', 'WhatsApp template variables do not match the approved template.');

echo "Messaging helper test passed.\n";
