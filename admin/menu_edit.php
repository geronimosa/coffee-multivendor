<?php
require_once '../includes/db.php';

$restaurantId = $_GET['rid'] ?? null;
$itemId = $_GET['id'] ?? null;

if (!$restaurantId || !$itemId) {
    die("Missing parameters.");
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
        $stmt = $pdo->prepare("UPDATE menu_items SET name = ?, category = ?, price = ?, variant_options = ? WHERE id = ? AND restaurant_id = ?");
        $stmt->execute([$name, $category, $price, $variantsJson, $itemId, $restaurantId]);
        header("Location: menu.php?rid=$restaurantId");
        exit;
    }
}

// Fetch item to edit
$stmt = $pdo->prepare("SELECT * FROM menu_items WHERE id = ? AND restaurant_id = ?");
$stmt->execute([$itemId, $restaurantId]);
$item = $stmt->fetch();

if (!$item) {
    die("Menu item not found.");
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Menu Item</title>
    <style>
        textarea { width: 100%; height: 150px; }
        label { display: block; margin-top: 10px; }
        input[type=text], input[type=number] { width: 100%; padding: 6px; }
    </style>
    <link rel="stylesheet" href="/coffee/assets/css/theme.css">
</head>
<body>

<h2>Edit Menu Item</h2>
<a href="menu.php?rid=<?= $restaurantId ?>">⬅ Back to Menu</a>

<?php if (!empty($error)): ?>
<p style="color: red;"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<form method="POST">
    <label>Name:</label>
    <input type="text" name="name" value="<?= htmlspecialchars($item['name']) ?>" required>

    <label>Category:</label>
    <input type="text" name="category" value="<?= htmlspecialchars($item['category']) ?>">

    <label>Base Price:</label>
    <input type="number" step="0.01" name="price" value="<?= htmlspecialchars($item['price']) ?>">

    
    <label>Variant Options:</label>
    <div id="variant-container">
        <!-- Variant rows will go here -->
    </div>
    <button type="button" onclick="addVariant()">+ Add Variant</button>

    <!-- Hidden field to store JSON -->
    <input type="hidden" name="variant_options" id="variant_options">

    <button type="submit">Save Changes</button>
</form>
<script>
    const existingVariants = <?= isset($item['variant_options']) ? json_encode(json_decode($item['variant_options'])) : '[]' ?>;
</script>
<script src="menu_edit.js"></script>

</body>
</html>