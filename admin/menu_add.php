<?php
require_once '../includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
if (!$restaurantId) {
    die("Missing restaurant ID.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $category = trim($_POST['category']);
    $price = floatval($_POST['price']);
    $variantsJson = $_POST['variant_options'];

    // Validate JSON
    json_decode($variantsJson);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = "Invalid JSON in variant options.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO menu_items (restaurant_id, name, category, price, variant_options) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$restaurantId, $name, $category, $price, $variantsJson]);
        header("Location: menu.php?rid=$restaurantId");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Menu Item</title>
    <style>
        textarea { width: 100%; height: 150px; }
        label { display: block; margin-top: 10px; }
        input[type=text], input[type=number] { width: 100%; padding: 6px; }
    </style>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
</head>
<body>

<h2>Add New Menu Item</h2>
<a href="menu.php?rid=<?= $restaurantId ?>">⬅ Back to Menu</a>

<?php if (!empty($error)): ?>
<p style="color: red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST">
    <label>Name:</label>
    <input type="text" name="name" required>

    <label>Category:</label>
    <input type="text" name="category">

    <label>Base Price:</label>
    <input type="number" step="0.01" name="price" required>

    <label>Variant Options (JSON):</label>
    <textarea name="variant_options" required>[
  { "label": "Small", "price": 30 },
  { "label": "Medium", "price": 35 },
  { "label": "Large", "price": 40 }
]</textarea>

    <button type="submit">Add Item</button>
</form>

</body>
</html>