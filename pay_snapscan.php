<?php
// pay_snapscan.php?rid=123&order_id=456&amount=35500
require_once __DIR__ . '/includes/db.php';

$rid = $_GET['rid'] ?? '';
$order_id = $_GET['order_id'] ?? '';
$amount = $_GET['amount'] ?? 0; // amount in cents, e.g. 35500 = R355.00

$stmt = $pdo->prepare("SELECT snapscan_code, snapscan_api_key FROM restaurants WHERE id = ?");
$stmt->execute([$rid]);
$restaurant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$restaurant || empty($restaurant['snapscan_code']) || empty($restaurant['snapscan_api_key'])) {
    die("SnapScan credentials not configured for this restaurant.");
}

$snapCode = $restaurant['snapscan_code'];
$apiKey = $restaurant['snapscan_api_key'];

$data = [
    'amount' => (int) $amount,
    'snapCode' => $snapCode,
    'merchantReference' => "order_$order_id",
    'successRedirectUrl' => "https://qr.vlocitycomms.com/coffee/order_success.php?order=$order_id",
    'failRedirectUrl' => "https://qr.vlocitycomms.com/coffee/order_failed.php?order=$order_id",
    'extra' => [
        'orderId' => $order_id,
        'restaurantId' => $rid
    ]
];

// Call SnapScan API
$ch = curl_init("https://pos.snapscan.io/api/v1/create-payment-request");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);

if (!empty($result['checkoutUrl'])) {
    // Redirect to SnapScan checkout
    header("Location: " . $result['checkoutUrl']);
    exit;
} else {
    echo "Failed to initiate SnapScan payment.";
    echo "<pre>" . print_r($result, true) . "</pre>";
}
?>
