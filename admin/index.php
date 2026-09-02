<?php
require_once '../includes/db.php';

$token = $_GET['token'] ?? null;
$uid   = $_GET['uid'] ?? null;

if (!$token && !$uid) {
    echo "Invalid access";
    exit;
}

$restaurants = [];
$userId = null;

// Case 1: Login via token (from login_tokens table)
if ($token) {
    $stmt = $pdo->prepare("SELECT user_id FROM login_tokens WHERE token = ?");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        die("Invalid or expired login link.");
    }

    $userId = $row['user_id'];

    // Get restaurants linked to this user
    $stmt = $pdo->prepare("
        SELECT r.id, r.name
        FROM restaurant_users ru
        JOIN restaurants r ON ru.restaurant_id = r.id
        WHERE ru.user_id = ?
    ");
    $stmt->execute([$userId]);
    $restaurants = $stmt->fetchAll();
}

// Case 2: Login via uid (from restaurants table)
if ($uid) {
    $stmt = $pdo->prepare("
        SELECT id, name
        FROM restaurants
        WHERE uid = ?
    ");
    $stmt->execute([$uid]);
    $restaurants = $stmt->fetchAll();
}

// Redirect logic
if (count($restaurants) === 1) {
    $restaurantId = $restaurants[0]['id'];
    header("Location: dashboard.php?rid=$restaurantId");
    exit;
} elseif (count($restaurants) > 1 && $userId) {
    // If multiple restaurants and user context is available
    header("Location: select_restaurant.php?user_id=$userId");
    exit;
} else {
    die("No associated restaurants found.");
}
?>