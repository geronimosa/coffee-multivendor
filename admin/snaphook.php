<?php
date_default_timezone_set('Africa/Johannesburg');

$rawPost = file_get_contents("php://input");
$data = json_decode($rawPost, true);

// Optional log for debugging
file_put_contents("snaps_log.txt", date('Y-m-d H:i:s') . " | " . $rawPost . PHP_EOL, FILE_APPEND);

// Basic validation
if (!is_array($data) || !isset($data['reference'])) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

// Extract key fields
$reference        = $data['reference'] ?? null;
$status           = $data['status'] ?? null;
$amount           = $data['amount'] ?? null;
$snapscan_ref     = $data['snapscan_reference'] ?? null;
$json_payload     = json_encode($data);

// Connect to database
require_once __DIR__ . '/../includes/db.php';

try {
    // Insert webhook into log table
    $stmt = $pdo->prepare("
        INSERT INTO snapscan_webhooks (snapscan_reference, reference, amount, status, payload)
        VALUES (:snapscan_reference, :reference, :amount, :status, :payload)
    ");

    $stmt->execute([
        'snapscan_reference' => $snapscan_ref,
        'reference'          => $reference,
        'amount'             => $amount,
        'status'             => $status,
        'payload'            => $json_payload
    ]);

    // Optionally update order status if completed
    if ($status === 'completed') {
        $stmt = $pdo->prepare("UPDATE orders SET status = 'paid', payment_status = 'paid', payment_method = 'snapscan', paid_at = NOW(), payment_reference = ? WHERE id = ?");
        $stmt->execute([$snapscan_ref, $reference]);
        
        
                // Email webhook info to s@vlocity.co.za
        $to = 's@vlocity.co.za';
        $subject = "SnapScan Payment Received: Ref $reference";

        $amountRand = number_format($amount / 100, 2); // cents to R
        $message = "
        SnapScan webhook received:

        Reference: $reference
        SnapScan Ref: $snapscan_ref
        Amount: R$amountRand
        Status: $status

        Full Payload:
        $rawPost

        Received At: " . date('Y-m-d H:i:s') . "
        ";

        $headers = 'From: steve@vlocitycommunications.com' . "\r\n" .
                   'Reply-To: no-reply@vlocitycommunications.com' . "\r\n" .
                   'X-Mailer: PHP/' . phpversion();

        mail($to, $subject, $message, $headers);
        
    }

    http_response_code(200);
    echo 'OK';
} catch (PDOException $e) {
    file_put_contents("snaps_error_log.txt", date('Y-m-d H:i:s') . " | DB Error: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo 'Database error';
}
