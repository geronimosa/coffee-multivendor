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

echo "Messaging helper test passed.\n";
