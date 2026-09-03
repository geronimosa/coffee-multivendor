<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/vendor_auth.php';

$legacyId=filter_input(INPUT_GET,'rid',FILTER_VALIDATE_INT)?:filter_input(INPUT_POST,'rid',FILTER_VALIDATE_INT)?:0;
$slug=trim((string)($_GET['slug']??$_POST['slug']??''));
$stmt=$pdo->prepare($slug!==''?'SELECT id,name,slug FROM restaurants WHERE slug=? LIMIT 1':'SELECT id,name,slug FROM restaurants WHERE id=? LIMIT 1');
$stmt->execute([$slug!==''?$slug:$legacyId]);
$restaurant=$stmt->fetch();
if(!$restaurant){http_response_code(404);exit('Vendor not found.');}
$restaurantId=(int)$restaurant['id'];
if($_SERVER['REQUEST_METHOD']==='GET'&&$slug===''&&$legacyId){redirect('/product/'.rawurlencode($restaurant['slug']).'/new');}
require_vendor_admin_access($pdo,$restaurantId,$restaurant['slug']);
$error=null;
$variantsJson=(string)($_POST['variant_options']??'[{"label":"Small","price":30},{"label":"Medium","price":35},{"label":"Large","price":40}]');

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    $name=trim((string)($_POST['name']??''));$category=trim((string)($_POST['category']??''));$takeawayEnabled=isset($_POST['takeaway_enabled'])?1:0;$variants=json_decode($variantsJson,true);
    if($name===''||$category===''){$error='Product name and category are required.';}
    elseif(!is_array($variants)||!$variants){$error='Add at least one valid option.';}
    else{foreach($variants as $variant){if(!is_array($variant)||trim((string)($variant['label']??''))===''||!is_numeric($variant['price']??null)||(float)$variant['price']<0){$error='Every option needs a label and a valid price.';break;}}}
    if(!$error){
        $price=min(array_map(fn($v)=>(float)$v['price'],$variants));
        $pdo->prepare('INSERT INTO menu_items(restaurant_id,name,category,price,variant_options,takeaway_enabled) VALUES(?,?,?,?,?,?)')->execute([$restaurantId,$name,$category,$price,json_encode($variants,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$takeawayEnabled]);
        $itemId=(int)$pdo->lastInsertId();audit_log($pdo,'menu_item.created','menu_item',(string)$itemId,$restaurantId,['name'=>$name]);
        redirect('/product/'.rawurlencode($restaurant['slug']));
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Add product · <?=e($restaurant['name'])?></title><link rel="stylesheet" href="/assets/css/super-admin.css?v=20260903-2"></head><body><header class="topbar"><a href="/product/<?=e($restaurant['slug'])?>">QRKiosk · <?=e($restaurant['name'])?></a></header><main class="container product-form-shell"><div class="actions"><a class="button secondary" href="/product/<?=e($restaurant['slug'])?>">← Products</a><h1>Add product</h1></div><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?><form method="post" class="card product-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="slug" value="<?=e($restaurant['slug'])?>"><div class="grid"><div><label for="name">Product name</label><input id="name" name="name" value="<?=e($_POST['name']??'')?>" required></div><div><label for="category">Category</label><input id="category" name="category" value="<?=e($_POST['category']??'')?>" placeholder="Coffee" required></div></div><label class="checkbox-row"><input type="checkbox" name="takeaway_enabled" value="1" <?=!isset($_POST['csrf_token'])||isset($_POST['takeaway_enabled'])?'checked':''?>><span><strong>Available for takeaway</strong><small>Show this product on the public order-for-collection menu.</small></span></label><section class="option-editor"><div class="option-editor-head"><div><h2>Sizes and options</h2><p class="muted">Each option can have its own price.</p></div><button class="button secondary" type="button" onclick="addVariant()">Add option</button></div><div id="variant-container"></div><input type="hidden" name="variant_options" id="variant_options"></section><button type="submit">Create product</button></form></main><script>const existingVariants=<?=json_encode(json_decode($variantsJson,true),JSON_UNESCAPED_UNICODE)?>;</script><script src="/admin/menu_edit.js"></script></body></html>
