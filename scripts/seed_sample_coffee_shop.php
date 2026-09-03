<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/env.php';
load_environment(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/../includes/db.php';

$vendorId = 1;
$replace = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--replace') {
        $replace = true;
        continue;
    }
    if (preg_match('/^--vendor=(\d+)$/', $argument, $matches)) {
        $vendorId = (int) $matches[1];
        continue;
    }
    fwrite(STDERR, "Usage: php scripts/seed_sample_coffee_shop.php [--vendor=1] [--replace]\n");
    exit(1);
}

if ($vendorId < 1) {
    fwrite(STDERR, "Vendor ID must be a positive integer.\n");
    exit(1);
}

$vendorStatement = $pdo->prepare('SELECT id, name FROM restaurants WHERE id = ?');
$vendorStatement->execute([$vendorId]);
$vendor = $vendorStatement->fetch();
if (!$vendor) {
    fwrite(STDERR, "Vendor {$vendorId} does not exist. Create it before loading the sample menu.\n");
    exit(1);
}

/** @return array<int, array{label:string, price:float}> */
function variants(array $prices): array
{
    $result = [];
    foreach ($prices as $label => $price) {
        $result[] = ['label' => (string) $label, 'price' => (float) $price];
    }
    return $result;
}

$menu = [
    ['Coffee', 'Espresso', variants(['Single' => 29, 'Double' => 34])],
    ['Coffee', 'Americano', variants(['Small' => 35, 'Medium' => 40, 'Large' => 45])],
    ['Coffee', 'Cappuccino', variants(['Small' => 39, 'Medium' => 45, 'Large' => 49])],
    ['Coffee', 'Flat White', variants(['Standard' => 41, 'Large' => 47])],
    ['Coffee', 'Caffè Latte', variants(['Small' => 41, 'Medium' => 46, 'Large' => 51])],
    ['Coffee', 'Caffè Mocha', variants(['Small' => 48, 'Medium' => 53, 'Large' => 58])],
    ['Coffee', 'Filter Coffee', variants(['Small' => 31, 'Medium' => 35, 'Large' => 38])],
    ['Coffee', 'Red Cappuccino', variants(['Small' => 43, 'Medium' => 48, 'Large' => 53])],
    ['Hot Drinks', 'Hot Chocolate', variants(['Small' => 45, 'Medium' => 50, 'Large' => 55])],
    ['Hot Drinks', 'Chai Latte', variants(['Small' => 46, 'Medium' => 51, 'Large' => 56])],
    ['Hot Drinks', 'Pot of Tea', variants(['Five Roses' => 30, 'Rooibos' => 30, 'Earl Grey' => 34, 'Green Tea' => 34])],
    ['Cold Drinks', 'Iced Americano', variants(['Regular' => 39, 'Large' => 45])],
    ['Cold Drinks', 'Iced Latte', variants(['Regular' => 45, 'Large' => 51])],
    ['Cold Drinks', 'Flavoured Iced Latte', variants(['Vanilla' => 52, 'Caramel' => 52, 'Hazelnut' => 52])],
    ['Cold Drinks', 'Coffee Frappé', variants(['Classic' => 59, 'Mocha' => 64, 'Caramel' => 64])],
    ['Cold Drinks', 'Fruit Smoothie', variants(['Berry' => 62, 'Mango' => 62, 'Peanut Butter & Banana' => 66])],
    ['Cold Drinks', 'Fresh Juice', variants(['Orange 300ml' => 38, 'Apple 300ml' => 38])],
    ['Cold Drinks', 'Soft Drink', variants(['Coca-Cola 300ml' => 32, 'Coca-Cola No Sugar 300ml' => 32, 'Appletiser 275ml' => 39])],
    ['Cold Drinks', 'Bottled Water', variants(['Still 500ml' => 25, 'Sparkling 500ml' => 27])],
    ['Breakfast', 'Bacon & Egg Breakfast Roll', variants(['Standard' => 64, 'Add Cheddar' => 73])],
    ['Breakfast', 'Breakfast Croissant', variants(['Egg & Cheddar' => 62, 'Bacon, Egg & Cheddar' => 76])],
    ['Breakfast', 'Smashed Avo on Toast', variants(['Classic' => 79, 'Add Poached Egg' => 91, 'Add Bacon' => 96])],
    ['Breakfast', 'Muesli & Yoghurt Bowl', variants(['Seasonal Fruit' => 68, 'Berry Compote' => 72])],
    ['Breakfast', 'Warm Oats', variants(['Cinnamon & Honey' => 59, 'Peanut Butter & Banana' => 67])],
    ['Bakery', 'Freshly Baked Muffin', variants(['Blueberry' => 49, 'Triple Chocolate' => 49, 'Bran & Raisin' => 49])],
    ['Bakery', 'Butter Croissant', variants(['Plain' => 36, 'Cheese' => 45, 'Almond' => 49])],
    ['Bakery', 'Banana Bread', variants(['Plain' => 39, 'Toasted with Butter' => 44])],
    ['Bakery', 'Cake Slice', variants(['Carrot Cake' => 58, 'Chocolate Cake' => 58, 'Baked Cheesecake' => 62])],
    ['Bakery', 'Chocolate Brownie', variants(['Standard' => 42])],
    ['Bakery', 'Cookie', variants(['Chocolate Chunk' => 28, 'Oat & Cranberry' => 28])],
    ['Light Meals', 'Toasted Sandwich', variants(['Cheddar & Tomato' => 55, 'Chicken Mayo' => 69, 'Bacon, Egg & Cheddar' => 79, 'Tuna Melt' => 72])],
    ['Light Meals', 'Chicken Mayo Wrap', variants(['Standard' => 82, 'Add Avocado' => 94])],
    ['Light Meals', 'Roast Vegetable & Feta Wrap', variants(['Standard' => 79])],
    ['Light Meals', 'Chicken & Avo Salad', variants(['Standard' => 92])],
    ['Extras', 'Extra Espresso Shot', variants(['Single Shot' => 10, 'Double Shot' => 18])],
    ['Extras', 'Milk Alternative', variants(['Oat Milk' => 10, 'Almond Milk' => 10, 'Soy Milk' => 8])],
    ['Extras', 'Flavoured Syrup', variants(['Vanilla' => 10, 'Caramel' => 10, 'Hazelnut' => 10])],
];

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'UPDATE restaurants
         SET name = ?, storefront_message = ?, theme_primary = ?, theme_accent = ?,
             theme_background = ?, theme_surface = ?, theme_text = ?
         WHERE id = ?'
    )->execute([
        'Brewed Café',
        'Fresh coffee, breakfast and café favourites. Order here and collect when ready.',
        '#174C3C', '#D9A441', '#F4F0E8', '#FFFFFF', '#17211C', $vendorId,
    ]);

    if ($replace) {
        $pdo->prepare('DELETE FROM menu_items WHERE restaurant_id = ?')->execute([$vendorId]);
    }

    $find = $pdo->prepare('SELECT id FROM menu_items WHERE restaurant_id = ? AND name = ? ORDER BY id LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO menu_items (restaurant_id, name, category, price, variant_options) VALUES (?, ?, ?, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE menu_items SET category = ?, price = ?, variant_options = ? WHERE id = ? AND restaurant_id = ?'
    );
    $inserted = 0;
    $updated = 0;

    foreach ($menu as [$category, $name, $itemVariants]) {
        $basePrice = min(array_column($itemVariants, 'price'));
        $variantJson = json_encode($itemVariants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $find->execute([$vendorId, $name]);
        $itemId = $find->fetchColumn();
        if ($itemId === false) {
            $insert->execute([$vendorId, $name, $category, $basePrice, $variantJson]);
            $inserted++;
        } else {
            $update->execute([$category, $basePrice, $variantJson, (int) $itemId, $vendorId]);
            $updated++;
        }
    }

    $pdo->commit();
    echo "Brewed Café sample loaded for vendor {$vendorId}: {$inserted} inserted, {$updated} updated.\n";
    if (!$replace) {
        echo "Existing products not in the sample menu were preserved. Use --replace for an exact reset.\n";
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}
