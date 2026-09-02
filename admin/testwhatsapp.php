<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Twilio\Rest\Client;

$sid = getenv('TWILIO_ACCOUNT_SID');
$token = getenv('TWILIO_AUTH_TOKEN');

$twilio = new Client($sid, $token);

$to = getenv('TWILIO_TEST_TO');
$from = getenv('TWILIO_WHATSAPP_FROM');

// Sample values
$name = 'Steve';
$orderId = '1055';
$total = 94.50;

$items = [
    ['name' => 'Cappuccino', 'variant_label' => 'Large', 'quantity' => 2],
    ['name' => 'Muffin', 'variant_label' => 'Chocolate', 'quantity' => 1],
];

// Format item list
$itemText = "";
foreach ($items as $item) {
    $itemText .= "- {$item['quantity']}x {$item['name']} ({$item['variant_label']}) ";
}

// Build payload
$payload = [
    'from' => $from,
    'contentSid' => getenv('TWILIO_CONTENT_SID_ORDER_READY'),
    'contentVariables' => json_encode([
        '1' => $name,
        '2' => $orderId,
        '3' => trim($itemText),
        '4' => "R" . number_format($total, 2)
    ])
];

try {
    $message = $twilio->messages->create($to, $payload);
    echo "✅ Message sent. SID: " . $message->sid;
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
