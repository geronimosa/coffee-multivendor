<?php
require_once __DIR__ . '/../vendor/autoload.php'; // Ensure this is correct
use Twilio\Rest\Client;

function old_sendOrderReadyTemplate($phone, $name, $orderId, $items, $total) {
    $sid = getenv('TWILIO_ACCOUNT_SID');
    $token = getenv('TWILIO_AUTH_TOKEN');
    $twilio = new Client($sid, $token);

    $from = getenv('TWILIO_WHATSAPP_FROM');
    $to   = formatPhoneNumberForWhatsApp($phone);

    // Build item summary: "2x Cappuccino (Large), 1x Muffin (Chocolate)"
    $itemTextArray = [];
    foreach ($items as $item) {
        $qty = $item['quantity'];
        $label = trim($item['variant_label']);
        $desc = "{$qty}x {$item['name']}";
        if ($label) {
            $desc .= " ({$label})";
        }
        $itemTextArray[] = $desc;
    }
    $itemText = implode(', ', $itemTextArray);  // Comma-separated, WhatsApp-safe

    // Send the WhatsApp message using the template
    $twilio->messages->create($to, [
        'from' => $from,
        'contentSid' => getenv('TWILIO_CONTENT_SID_ORDER_READY'),
        'contentVariables' => json_encode([
            '1' => $name,
            '2' => $orderId,
            '3' => $itemText,
            '4' => "R" . number_format($total, 2, '.', '')
        ])
    ]);
}

function sendOrderReadyTemplate($phone, $name, $orderId, $items, $total) {
    $sid = getenv('TWILIO_ACCOUNT_SID');
    $token = getenv('TWILIO_AUTH_TOKEN');
    $twilio = new Client($sid, $token);

    $from = getenv('TWILIO_WHATSAPP_FROM');
    $to   = formatPhoneNumberForWhatsApp($phone);
    
    $filename = "slip_{$orderId}.jpg";

    $twilio->messages->create($to, [
        'from' => $from,
        'contentSid' => getenv('TWILIO_CONTENT_SID_ORDER_READY'),
        'contentVariables' => json_encode([
            '1' => $name,
            '2' => $orderId,
            '3' => $filename
        ])
    ]);
    
}

function generateSlipImage($orderId, $restaurantId) {
    $url = "https://coffee.tatu.co.za/admin/slip_image.php?id=$orderId&rid=$restaurantId";
    $output = "/var/www/coffee/images/slip_{$orderId}.jpg";
    
    if (!file_exists(dirname($output))) {
        mkdir(dirname($output), 0755, true);
    }

   // shell_exec("wkhtmltoimage --width 300 '$url' '$output'");
    $cmd = "wkhtmltoimage --width 300 '$url' '$output'";
    $outputLog = shell_exec("$cmd 2>&1");

    return $output;
}

function formatPhoneNumberForWhatsApp($raw) {
    // Remove all non-digit characters
    $digits = preg_replace('/\D+/', '', $raw);

    // Handle local South African numbers
    if (strlen($digits) === 10 && $digits[0] === '0') {
        $digits = '27' . substr($digits, 1);
    }

    // Handle already correct numbers (e.g., 27824403123)
    if (strlen($digits) === 11 && strpos($digits, '27') === 0) {
        return 'whatsapp:+'.$digits;
    }

    // Fallback: return empty or handle as needed
    return false;
}
