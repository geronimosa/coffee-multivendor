<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "Usage: php scripts/seed_bricklane_eatery.php --confirm [--dry-run]\n");
    exit(1);
}
$dryRun = in_array('--dry-run', $argv, true);

require_once __DIR__ . '/../includes/env.php';
load_environment(dirname(__DIR__) . '/.env');
require_once __DIR__ . '/../includes/db.php';

/** @return array<int,array{label:string,price:float}> */
function ble_variants(array $prices): array {
    $rows=[]; foreach($prices as $label=>$price) $rows[]=['label'=>(string)$label,'price'=>(float)$price]; return $rows;
}
function ble_item(string $category,string $name,float|array $price): array {
    return [$category,$name,is_array($price)?ble_variants($price):ble_variants(['Standard'=>$price])];
}

$menu = [
    ble_item('Breakfast','French Toast',['Berry compote & cream'=>65,'Nutella & banana'=>65,'Bacon & maple syrup'=>65]),
    ble_item('Breakfast','Creamy Oats',55), ble_item('Breakfast','Breakfast Bowl',75), ble_item('Breakfast','Breakfast Wrap',75),
    ble_item('Breakfast','BLE Croissant',90), ble_item('Breakfast','Rise and Shine Salmon',90), ble_item('Breakfast','Rise and Shine',70),
    ble_item('Breakfast','Smash and Go',75), ble_item('Breakfast','Savoury Mince on Toast',85), ble_item('Breakfast','Mini Breakfast',70),
    ble_item('Breakfast','Full English',110), ble_item('Breakfast','Eggs Benedict',['Classic'=>70,'Bacon or ham'=>90,'Salmon'=>115]),
    ble_item('Breakfast','Breakfast Bagel',90), ble_item('Breakfast','Salmon Bagel',115), ble_item('Breakfast','Beetroot and Avo Toast',90),
    ble_item('Breakfast','Flapjack Stack',85), ble_item('Breakfast','Bricklane Stack',90),
    ble_item('Breakfast','Classic Omelette',['Bacon or ham & mushroom'=>90,'Chicken & mushroom'=>90]),
    ble_item('Breakfast','Green Omelette',85), ble_item('Breakfast','Savoury Mince Omelette',95),

    ble_item('Sandwiches','Cheddar Cheese & Tomato',60), ble_item('Sandwiches','Cheddar, Mushrooms & Caramelised Onions',70),
    ble_item('Sandwiches','Gypsy Ham, Mozzarella & Tomato',70), ble_item('Sandwiches','Chicken Mayo & Basil Pesto',70),
    ble_item('Sandwiches','Bacon, Egg & Mozzarella',80), ble_item('Sandwiches','Bacon, Avocado & Mozzarella',90),
    ble_item('Sandwiches','Cajun Chicken, Mozzarella, Bacon & Avocado',120), ble_item('Sandwiches','Vegan Cheese & Tomato',75),
    ble_item('Sandwiches','Vegan Cheese, Mushrooms & Caramelised Onion',80), ble_item('Sandwiches','Sauteed Mushroom & Vegan Mozzarella',85),
    ble_item('Pastries','Assorted Muffins',40), ble_item('Pastries','Baked Croissant',['Butter and jam'=>35,'Add cheese'=>50]),
    ble_item('Doggy Menu','Bag of Biscuits',30), ble_item('Doggy Menu','Doggy Liver Bites 125g',25),
    ble_item('Doggy Menu','Chicken Bites 150g',30), ble_item('Doggy Menu','Doggy Boerewors 100g',30), ble_item('Doggy Menu','Cool Dogs Biltong Ice Cream',70),

    ble_item('Tapas','Mac & Cheese Balls',55), ble_item('Tapas','Blooming Onion',55), ble_item('Tapas','Chilli Popper Bombs',60),
    ble_item('Tapas','Chicken Spring Rolls',50), ble_item('Tapas','Chicken Popcorn',55), ble_item('Tapas','Sticky Chicken Wings',75),
    ble_item('Tapas','Chicken Croquettes',75), ble_item('Tapas','Calamari Strips',75), ble_item('Tapas','Panko Crumbed Prawns',80), ble_item('Tapas','Grilled Prawns',80),
    ble_item('Pasta','Mac n Cheese',75), ble_item('Pasta','Basil Pesto Penne',['Plain'=>80,'Creamy'=>90]), ble_item('Pasta','Chipotle Chicken Linguine',115),
    ble_item('Pasta','Beef Lasagne and Side',130), ble_item('Pasta','Vegetable Lasagne and Side',120), ble_item('Pasta','Alfredo Linguine',['Chicken'=>120,'Bacon'=>115]),

    ble_item('Light Meals','Loaded Fries',75), ble_item('Light Meals','Spicy Chicken Livers',70), ble_item('Light Meals','Soup of the Day',70),
    ble_item('Light Meals','Crumbed Chicken Loaded Spud',90), ble_item('Light Meals','Plankie Steak',115), ble_item('Light Meals','Chicken Schnitzel',110),
    ble_item('Light Meals','Chicken Cordon Bleu',125), ble_item('Light Meals','Bangers and Mash',85),
    ble_item('Light Meals','Quesadillas',['Black bean mince'=>100,'Chipotle chicken'=>100]), ble_item('Light Meals','Nachos',['Plain or spicy'=>100,'Add chicken'=>125,'Add black bean mince'=>130]),
    ble_item('Poke Bowls','Chicken Poke Bowl',130), ble_item('Poke Bowls','Steak Poke Bowl',140), ble_item('Poke Bowls','Salmon Poke Bowl',150),
    ble_item('Kiddies','Scrambled Eggs & Chips',45), ble_item('Kiddies','French Toast & Syrup',45), ble_item('Kiddies','Toasted Cheese & Chips',45),
    ble_item('Kiddies','Toasted Chicken Mayo & Chips',50), ble_item('Kiddies','Hawaiian Mini Flatbread',50), ble_item('Kiddies','Kiddies Flapjack',50),
    ble_item('Kiddies','Chicken Popcorn & Chips',50), ble_item('Kiddies','Mac & Cheese',50),

    ble_item('Burgers','Classic Burger',110), ble_item('Burgers','Cheese Burger',130), ble_item('Burgers','Schnitzel Burger',145),
    ble_item('Burgers','Bacon Burger',135), ble_item('Burgers','Chilli Popper Burger',145), ble_item('Burgers','Blue Cheese and Fig Burger',140),
    ble_item('Burgers','Bacon and Brie Burger',135), ble_item('Burgers','Pulled Pork Bagel Burger',160), ble_item('Burgers','Best of Both Burger',200),
    ble_item('Meat Free Burgers','BLE Veggie / Vegan Burger',115), ble_item('Meat Free Burgers','Cheese & Onion Meat Free Burger',165), ble_item('Meat Free Burgers','Mozzarella & Avo Meat Free Burger',175),
    ble_item('Wraps','Butternut Wrap',85), ble_item('Wraps','Chicken Wrap',95), ble_item('Wraps','Salmon Wrap',135),

    ble_item('Combos','Rib & Wings',200), ble_item('Combos','Steak & Wings',220), ble_item('Combos','Hake & Calamari',195),
    ble_item('Combos','Grilled Hake and Prawn',199), ble_item('Combos','Nibble and Sip Platter',220),
    ble_item('Combo Extras','200g Sirloin Steak',95), ble_item('Combo Extras','5 Grilled Prawns',75), ble_item('Combo Extras','200g Chicken Wings',70),
    ble_item('Flatbreads','Cheesy Classic',80), ble_item('Flatbreads','Vegetable',95), ble_item('Flatbreads','Mediterranean',110),
    ble_item('Flatbreads','Butternut & Feta',115), ble_item('Flatbreads','Pesto Chicken',115), ble_item('Flatbreads','Pork Belly',130),
    ble_item('Flatbreads','Champ',125), ble_item('Flatbreads','Steak',155), ble_item('Flatbreads','Bacon, Brie & Fig',135),
    ble_item('Flatbreads','Nachos Flatbread',['Plain or spicy'=>125,'Add chicken'=>155,'Add black bean mince'=>160]), ble_item('Flatbreads','Vegan',120),

    ble_item('Salads','Butternut & Beetroot',85), ble_item('Salads','Chicken Salad',95), ble_item('Salads','Butternut and Couscous',110),
    ble_item('Salads','Caesar Salad',['Chicken'=>115,'Bacon'=>110,'Salmon'=>145]),
    ble_item('Mains','Homemade Pot Pie',95), ble_item('Mains','Beer-Battered Fish',125), ble_item('Mains','Sticky Pork Ribs',['300g'=>135,'600g'=>230]),
    ble_item('Mains','Steak',['200g sirloin'=>160,'300g rump'=>185]), ble_item('Mains','Peri-Peri Half Chicken',135),
    ble_item('Mains','Thai Green Chicken Curry',115), ble_item('Mains','Chicken & Prawn Curry',130), ble_item('Mains','Hearty Beef Stew',120), ble_item('Mains','Crispy Pork Belly 250g',145),

    ble_item('Desserts','Homemade Ice Cream',65), ble_item('Desserts','Pancakes',['Cinnamon sugar'=>40,'Banana & chocolate or caramel'=>50,'Zesty orange sauce'=>50]),
    ble_item('Desserts','Homemade Chocolate Brownies',60), ble_item('Desserts','Apple Crumble',60), ble_item('Desserts','Chocolate Overload Waffle',80),
    ble_item('Desserts','Mixed Berry Waffle',75), ble_item('Desserts','Steamed Orange Pudding',68), ble_item('Desserts','Cake of the Day',65),
    ble_item('Desserts','Don Pedro',60), ble_item('Desserts','Irish Coffee',55),
    ble_item('Hot Beverages','Espresso',['Single'=>22,'Double'=>24]), ble_item('Hot Beverages','Americano',['Single'=>24,'Double'=>28]),
    ble_item('Hot Beverages','Cortado',33), ble_item('Hot Beverages','Cappuccino',['Single'=>32,'Double'=>34]), ble_item('Hot Beverages','Flat White',['Single'=>32,'Double'=>34]),
    ble_item('Hot Beverages','Cafe Latte',['Single'=>34,'Double'=>36]), ble_item('Hot Beverages','Red Cappuccino',['Single'=>34,'Double'=>36]), ble_item('Hot Beverages','Chai Latte',38),
    ble_item('Hot Beverages','Cafe Mocha',40), ble_item('Hot Beverages','Flavoured Latte',['Hazelnut'=>42,'Caramel fudge'=>42,'Vanilla'=>42]),
    ble_item('Hot Beverages','Hot Chocolate',38), ble_item('Hot Beverages','White Hot Chocolate',40), ble_item('Hot Beverages','Baby Chino',5),
    ble_item('Hot Beverages','Tea',['Ceylon'=>24,'Rooibos'=>24,'Green'=>26,'Chai'=>26,'Earl Grey'=>26]), ble_item('Hot Beverages','Alternative Milk',['Almond'=>5,'Oat'=>5]),

    ble_item('Soft Drinks','300ml Soda',26), ble_item('Soft Drinks','Pura Soda',34), ble_item('Soft Drinks','Appletiser',36), ble_item('Soft Drinks','Red Grapetiser',36),
    ble_item('Soft Drinks','Homemade Ice Tea',['Lemon'=>34,'Peach'=>34]), ble_item('Soft Drinks','100% Fruit Juice 500ml',['Orange'=>36,'Apple'=>36,'Mango'=>36,'Fruit cocktail'=>36]),
    ble_item('Soft Drinks','Rock Shandy',45), ble_item('Soft Drinks','Cordials',['Cola tonic'=>10,'Lime'=>10,'Passionfruit'=>10]), ble_item('Soft Drinks','Fitch & Leedes Mixers 200ml',25),
    ble_item('Soft Drinks','Water',['500ml still'=>28,'500ml sparkling'=>28,'1 litre still'=>38,'1 litre sparkling'=>38]),
    ble_item('Milkshakes & Smoothies','Classic Milkshake',['Small'=>30,'Large'=>40]), ble_item('Milkshakes & Smoothies','Coffee Shake - Large',40), ble_item('Milkshakes & Smoothies','Iced Coffee Frappe',32),
    ble_item('Milkshakes & Smoothies','Gourmet Milkshake',['Peanut brittle'=>45,'Chai'=>45,'Lemon cheesecake'=>45,'Nutella'=>45]),
    ble_item('Milkshakes & Smoothies','Berry Smoothie',60), ble_item('Milkshakes & Smoothies','Mango Smoothie',65), ble_item('Milkshakes & Smoothies','Green Smoothie',75),

    ble_item('Beers & Ciders','CBC Lager on Tap',['300ml'=>38,'500ml'=>45]), ble_item('Beers & Ciders','CBC Pale Ale on Tap',['300ml'=>40,'500ml'=>48]),
    ble_item('Beers & Ciders','CBC Amber Weiss on Tap',['300ml'=>40,'500ml'=>48]), ble_item('Beers & Ciders','Castle Lite on Tap',['300ml'=>38,'500ml'=>45]),
    ble_item('Beers & Ciders','Castle Lite Bottle',38), ble_item('Beers & Ciders','Black Label',38), ble_item('Beers & Ciders','Amstel Radler',40),
    ble_item('Beers & Ciders','Heineken',42), ble_item('Beers & Ciders','Heineken Silver',42), ble_item('Beers & Ciders','Windhoek Draught 440ml',48),
    ble_item('Beers & Ciders','Savanna',['Light'=>45,'Dry'=>45]), ble_item('Beers & Ciders','Hunters',['Dry'=>42,'Gold'=>42]), ble_item('Beers & Ciders','Rekorderlig Strawberry & Lime',50),
    ble_item('Beers & Ciders','Heineken Zero',42), ble_item('Beers & Ciders','Savanna Zero Lemon',42),
    ble_item('Cocktails','Cosmopolitan',65), ble_item('Cocktails','Bloody Mary',65), ble_item('Cocktails','Strawberry Daiquiri',79), ble_item('Cocktails','Mango Daiquiri',79),
    ble_item('Cocktails','Margarita',['Plain'=>75,'Frozen'=>75]), ble_item('Cocktails','Mango Margarita',['Plain'=>75,'Frozen'=>75]), ble_item('Cocktails','Espresso Martini',75),
    ble_item('Cocktails','Tequila Sunrise',75), ble_item('Cocktails','Berry Blaze',75), ble_item('Cocktails','Pina Colada',79), ble_item('Cocktails','Mojito',80),
    ble_item('Cocktails','Long Island Ice Tea',90), ble_item('Cocktails','Mimosas',['Glass'=>65,'Jug'=>200]), ble_item('Cocktails','Whiskey Sour',75), ble_item('Cocktails','Amber Spritz',55),

    ble_item('Signature Shooters','Nutty Irishman',25), ble_item('Signature Shooters','Springbokkie',22), ble_item('Signature Shooters','Blowjob',25),
    ble_item('Signature Shooters','After Eight',30), ble_item('Signature Shooters','Snake Bite',35), ble_item('Signature Shooters','Tequila Sunrise Shooter',35), ble_item('Signature Shooters','Jagerbombs x4',150),
    ble_item('Spirits','Gordons Gin',['Classic'=>30,'Pink'=>30]), ble_item('Spirits','Amarula Gin',32), ble_item('Spirits','Tanqueray',33),
    ble_item('Spirits','Bains Whisky',32), ble_item('Spirits','J&B Whisky',30), ble_item('Spirits','Jack Daniels',35), ble_item('Spirits','Jameson',38), ble_item('Spirits','Johnnie Walker Black',40),
    ble_item('Spirits','Spiced Gold Rum',30), ble_item('Spirits','Captain Morgan Dark Rum',30), ble_item('Spirits','Klipdrift Brandy',25), ble_item('Spirits','Richelieu 10 Years',35),
    ble_item('Spirits','Pushkin Vodka',22), ble_item('Spirits','Cruz Vodka',35), ble_item('Spirits','Grey Goose Vodka',50),
    ble_item('Spirits','El Jimador Tequila',['Silver'=>32,'Gold'=>32]), ble_item('Spirits','Espolon Reposada',50), ble_item('Spirits','Don Julio',95),
    ble_item('Liqueurs','Frangelico',28), ble_item('Liqueurs','Kahlua',28), ble_item('Liqueurs','Amarula',28), ble_item('Classic Shooters','Caramel Vodka',28),
    ble_item('Classic Shooters','Jagermeister',32), ble_item('Classic Shooters','Punchos Coffee',30), ble_item('Classic Shooters','Melktertjie',28),

    ble_item('Bubbly','Pongracz Brut',325), ble_item('Bubbly','Stellenrust Chenin Blanc Spumante Magnifico',250),
    ble_item('Bubbly','Stellenrust Rose Spumante Magnifico',250), ble_item('Bubbly','Durbanville Hills Sauvignon Blanc',['Glass'=>60,'Bottle'=>220]),
    ble_item('Wine - Sauvignon Blanc','De Grendel',245), ble_item('Wine - Sauvignon Blanc','Diemersdal',['Glass'=>70,'Bottle'=>200]),
    ble_item('Wine - Sauvignon Blanc','Durbanville Hills',190), ble_item('Wine - Sauvignon Blanc','Protea by Anthonij Rupert',['Glass'=>55,'Bottle'=>175]),
    ble_item('Wine - Chardonnay','Diemersdal Unwooded',['Glass'=>70,'Bottle'=>200]), ble_item('Wine - Chardonnay','Durbanville Hills Unwooded',190),
    ble_item('Wine - Chardonnay','Protea by Anthonij Rupert',['Glass'=>55,'Bottle'=>175]),
    ble_item('Wine - Chenin Blanc','Cederberg',245), ble_item('Wine - Chenin Blanc','Durbanville Hills',['Glass'=>60,'Bottle'=>190]),
    ble_item('Wine - Chenin Blanc','Saronsberg Earth in Motion',180), ble_item('Wine - Chenin Blanc','Protea by Anthonij Rupert',['Glass'=>55,'Bottle'=>175]),
    ble_item('Wine - White Blends','Buitenverwachting Buiten Blanc',['Glass'=>60,'Bottle'=>185]), ble_item('Wine - White Blends',"Leopard's Leap Chescato",['Glass'=>55,'Bottle'=>170]),
    ble_item('Wine - Rose','De Grendel',245), ble_item('Wine - Rose','Protea by Anthonij Rupert',['Glass'=>58,'Bottle'=>175]), ble_item('Wine - Rose','Nederburg Classic',['Glass'=>55,'Bottle'=>165]),
    ble_item('Wine - Cabernet Sauvignon','Warwick The First Lady',245), ble_item('Wine - Cabernet Sauvignon','Durbanville Hills',['Glass'=>60,'Bottle'=>185]),
    ble_item('Wine - Cabernet Sauvignon','Protea by Anthonij Rupert',['Glass'=>55,'Bottle'=>175]),
    ble_item('Wine - Shiraz','Diemersdal',['Glass'=>75,'Bottle'=>260]), ble_item('Wine - Shiraz','Saronsberg Provenance',250),
    ble_item('Wine - Shiraz','Durbanville Hills',190), ble_item('Wine - Shiraz','Protea by Anthonij Rupert',['Glass'=>55,'Bottle'=>175]),
    ble_item('Wine - Merlot','Diemersdal',['Glass'=>75,'Bottle'=>260]), ble_item('Wine - Merlot',"Alvi's Drift",185), ble_item('Wine - Merlot','Durbanville Hills',185),
    ble_item('Wine - Merlot','Protea by Anthonij Rupert',['Glass'=>55,'Bottle'=>175]),
    ble_item('Wine - Pinotage','Diemersdal',['Glass'=>75,'Bottle'=>260]), ble_item('Wine - Pinotage','Beyerskloof',200), ble_item('Wine - Pinotage','Durbanville Hills',185),
    ble_item('Wine - Pinotage',"Leopard's Leap",['Glass'=>55,'Bottle'=>175]),
    ble_item('Wine - Red Blends','Diemersdal Cabernet Sauvignon Merlot',['Glass'=>65,'Bottle'=>200]),
    ble_item('Wine - Red Blends',"Leopard's Leap Cabernet Sauvignon Merlot",['Glass'=>55,'Bottle'=>175]),
];

$seen=[];
foreach($menu as [$category,$name,$variants]){
    $key=mb_strtolower(trim($category).'|'.trim($name));
    if(isset($seen[$key])) throw new RuntimeException("Duplicate menu entry: {$category} / {$name}");
    $seen[$key]=true;
    if(trim($category)==='' || trim($name)==='' || $variants===[]) throw new RuntimeException('Menu entries require a category, name and price.');
    foreach($variants as $variant){
        if(trim($variant['label'])==='' || $variant['price']<0) throw new RuntimeException("Invalid price variant: {$category} / {$name}");
    }
}

$pdo->beginTransaction();
try {
    $stmt=$pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$stmt->execute(['geronimosa@gmail.com']);$creatorId=(int)$stmt->fetchColumn();
    if(!$creatorId) throw new RuntimeException('Super administrator not found.');
    $stmt=$pdo->prepare('SELECT id FROM restaurants WHERE slug=? LIMIT 1');$stmt->execute(['bricklane-eatery']);$vendorId=(int)$stmt->fetchColumn();
    $description='<p><strong>Bricklane Eatery</strong><br>142 The Quays, Park Lane, Century City, 7441, Cape Town, South Africa</p><p>Tuesday-Saturday 08:00-21:00<br>Sunday 09:00-16:00<br>Monday closed</p><p>All meals are freshly prepared. Please allow at least 25 minutes preparation time.</p>';
    if(!$vendorId){
        $pdo->prepare("INSERT INTO restaurants(name,slug,status,service_model,default_service_charge_percent,contact_email,contact_phone,unique_code,uid,created_by,theme_primary,theme_accent,theme_background,theme_surface,theme_text,logo_path,storefront_message,vendor_description) VALUES(?,?,'active','restaurant',10,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(['Bricklane Eatery','bricklane-eatery','info@blect.co.za','021 202 1191',bin2hex(random_bytes(12)),bin2hex(random_bytes(24)),$creatorId,'#4A3629','#D2B48C','#F2E3C8','#FFFDF8','#38281F','/assets/images/bricklane-eatery-logo.png','Order from your table and enjoy. We will let you know when your order is ready.',$description]);
        $vendorId=(int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE restaurants SET name=?,status='active',service_model='restaurant',default_service_charge_percent=10,contact_email=?,contact_phone=?,theme_primary=?,theme_accent=?,theme_background=?,theme_surface=?,theme_text=?,logo_path=?,storefront_message=?,vendor_description=? WHERE id=?")
            ->execute(['Bricklane Eatery','info@blect.co.za','021 202 1191','#4A3629','#D2B48C','#F2E3C8','#FFFDF8','#38281F','/assets/images/bricklane-eatery-logo.png','Order from your table and enjoy. We will let you know when your order is ready.',$description,$vendorId]);
    }
    $pdo->prepare("INSERT INTO users(email,name,role,active) VALUES(?,?,'restaurant_user',1) ON DUPLICATE KEY UPDATE name=VALUES(name),active=1")->execute(['info@blect.co.za','Ruan']);
    $stmt=$pdo->prepare('SELECT id FROM users WHERE email=?');$stmt->execute(['info@blect.co.za']);$ownerId=(int)$stmt->fetchColumn();
    $pdo->prepare("INSERT INTO restaurant_users(restaurant_id,user_id,role) VALUES(?,?,'admin') ON DUPLICATE KEY UPDATE role='admin'")->execute([$vendorId,$ownerId]);
    $find=$pdo->prepare('SELECT id FROM menu_items WHERE restaurant_id=? AND category=? AND name=? LIMIT 1');
    $insert=$pdo->prepare('INSERT INTO menu_items(restaurant_id,name,category,price,variant_options) VALUES(?,?,?,?,?)');
    $update=$pdo->prepare('UPDATE menu_items SET price=?,variant_options=? WHERE id=? AND restaurant_id=?');
    $inserted=$updated=0;
    foreach($menu as [$category,$name,$variants]){
        $base=min(array_column($variants,'price'));$json=json_encode($variants,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $find->execute([$vendorId,$category,$name]);$id=$find->fetchColumn();
        if($id){$update->execute([$base,$json,(int)$id,$vendorId]);$updated++;}else{$insert->execute([$vendorId,$name,$category,$base,$json]);$inserted++;}
    }
    $tableInsert=$pdo->prepare("INSERT IGNORE INTO dining_tables(restaurant_id,name,area,qr_token,status) VALUES(?,?,'Main dining',?,'active')");
    for($number=1;$number<=40;$number++) $tableInsert->execute([$vendorId,'Table '.$number,bin2hex(random_bytes(16))]);
    if($dryRun){
        $pdo->rollBack();
        echo "DRY RUN passed (rolled back): Bricklane Eatery vendor {$vendorId}, {$inserted} products would be inserted, {$updated} updated, 40 tables ensured.\n";
    }else{
        $pdo->commit();
        echo "Bricklane Eatery vendor {$vendorId}: {$inserted} products inserted, {$updated} updated, 40 tables ensured.\n";
    }
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
