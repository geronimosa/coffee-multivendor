<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/vendor_auth.php';
require_once __DIR__.'/../includes/phpqrcode/qrlib.php';

$slug=trim((string)($_GET['slug']??''));
$stmt=$pdo->prepare("SELECT * FROM restaurants WHERE slug=? AND status='active' LIMIT 1");$stmt->execute([$slug]);$vendor=$stmt->fetch();
if(!$vendor){http_response_code(404);exit('Vendor not found.');}
$vendorId=(int)$vendor['id'];
if(!staff_can_access($pdo,$vendorId)){redirect('/vendor/'.rawurlencode($slug));}
if(($vendor['service_model']??'kiosk')!=='restaurant'){http_response_code(404);exit('Table service is not enabled for this vendor.');}
$isAdmin=vendor_admin_can_access($pdo,$vendorId);$error=$message=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();
    if(!$isAdmin){http_response_code(403);exit('Vendor administrator access required.');}
    $action=(string)($_POST['action']??'');
    if($action==='create'){
        $name=trim(mb_substr((string)($_POST['name']??''),0,80));$area=trim(mb_substr((string)($_POST['area']??''),0,80));$capacity=max(0,min(100,(int)($_POST['capacity']??0)));
        if($name===''){$error='Enter a table name.';}else{try{$pdo->prepare('INSERT INTO dining_tables(restaurant_id,name,area,capacity,qr_token) VALUES(?,?,?,?,?)')->execute([$vendorId,$name,$area?:null,$capacity?:null,bin2hex(random_bytes(16))]);$message='Table created.';}catch(PDOException $e){$error=$e->getCode()==='23000'?'That table name is already in use.':'Unable to create the table.';}}
    }elseif($action==='toggle'){
        $tableId=(int)($_POST['table_id']??0);$status=($_POST['status']??'')==='inactive'?'inactive':'active';
        $pdo->prepare('UPDATE dining_tables SET status=? WHERE id=? AND restaurant_id=?')->execute([$status,$tableId,$vendorId]);$message='Table updated.';
    }
}

$stmt=$pdo->prepare("SELECT dt.*,tt.id tab_id,tt.status tab_status,tt.total tab_total,tt.amount_paid FROM dining_tables dt LEFT JOIN table_tabs tt ON tt.id=(SELECT t2.id FROM table_tabs t2 WHERE t2.dining_table_id=dt.id AND t2.status IN ('open','settlement') ORDER BY t2.id DESC LIMIT 1) WHERE dt.restaurant_id=? ORDER BY dt.area,dt.name");$stmt->execute([$vendorId]);$tables=$stmt->fetchAll();
$base=rtrim((string)env('APP_URL','https://coffee.tatu.co.za'),'/');
function table_qr_data(string $url):string{$tmp=tempnam(sys_get_temp_dir(),'table-qr-');QRcode::png($url,$tmp,QR_ECLEVEL_H,6);$data=base64_encode((string)file_get_contents($tmp));@unlink($tmp);return 'data:image/png;base64,'.$data;}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tables · <?=e($vendor['name'])?></title><link rel="stylesheet" href="/assets/css/super-admin.css?v=20260903-4"></head><body><header class="topbar"><a href="/vendor/<?=e($slug)?>"><?=e($vendor['name'])?> · Staff</a><a href="/vendor/<?=e($slug)?>">Kitchen queue</a></header><main class="container product-admin"><div class="actions vendor-toolbar"><div><p class="section-kicker">Restaurant service</p><h1>Tables</h1><p class="muted">Each table has its own permanent ordering QR code.</p></div></div>
<?php if($message):?><div class="notice"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<?php if($isAdmin):?><form method="post" class="card"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><h2>Add a table</h2><div class="grid"><div><label for="name">Table name or number</label><input id="name" name="name" placeholder="Table 12" required></div><div><label for="area">Area</label><input id="area" name="area" placeholder="Patio"></div><div><label for="capacity">Seats</label><input id="capacity" type="number" name="capacity" min="1" max="100"></div></div><p><button type="submit">Create table and QR</button></p></form><?php endif;?>
<section class="table-admin-grid"><?php foreach($tables as $table):$url=$base.'/shop/'.rawurlencode($slug).'/table/'.$table['qr_token'];?><article class="card table-admin-card"><div><span class="badge <?=($table['status']==='inactive')?'suspended':''?>"><?=e(ucfirst($table['status']))?></span><h2><?=e($table['name'])?></h2><p class="muted"><?=e($table['area']?:'General')?><?=!empty($table['capacity'])?' · '.(int)$table['capacity'].' seats':''?></p><?php if($table['tab_id']):?><p><strong>Open bill: R<?=number_format((float)$table['tab_total'],2)?></strong></p><?php else:?><p class="muted">Available</p><?php endif;?></div><img class="table-qr" src="<?=table_qr_data($url)?>" alt="Ordering QR code for <?=e($table['name'])?>"><?php if($table['tab_id']):?><a class="button" href="/vendor/<?=e($slug)?>/tabs/<?=(int)$table['tab_id']?>">View bill / cash up</a><?php endif;?><a class="button secondary" href="<?=e($url)?>" target="_blank">Open table menu</a><?php if($isAdmin):?><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="table_id" value="<?=(int)$table['id']?>"><input type="hidden" name="status" value="<?=$table['status']==='active'?'inactive':'active'?>"><button class="button secondary" type="submit"><?=$table['status']==='active'?'Disable':'Enable'?></button></form><?php endif;?><small class="muted table-url"><?=e($url)?></small></article><?php endforeach;?></section>
<?php if(!$tables):?><section class="card empty"><h2>No tables yet</h2><p>Create your first table to generate its ordering QR code.</p></section><?php endif;?></main></body></html>
