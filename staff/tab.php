<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/vendor_auth.php';
require_once __DIR__.'/../includes/restaurant_service.php';
require_once __DIR__.'/../includes/vendor_portal_nav.php';

$slug=trim((string)($_GET['slug']??''));$tabId=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT)?:filter_input(INPUT_POST,'tab_id',FILTER_VALIDATE_INT)?:0;
$stmt=$pdo->prepare("SELECT r.*,dt.name table_name,tt.status tab_status,tt.id tab_id FROM restaurants r JOIN table_tabs tt ON tt.restaurant_id=r.id JOIN dining_tables dt ON dt.id=tt.dining_table_id WHERE r.slug=? AND tt.id=? LIMIT 1");$stmt->execute([$slug,$tabId]);$vendor=$stmt->fetch();
if(!$vendor){http_response_code(404);exit('Table bill not found.');}$vendorId=(int)$vendor['id'];
if(!staff_can_access($pdo,$vendorId)){redirect('/vendor/'.rawurlencode($slug));}
$error=$message=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_csrf();$action=(string)($_POST['action']??'');
    try{
        $pdo->beginTransaction();refresh_table_tab_totals($pdo,$tabId);
        $stmt=$pdo->prepare('SELECT * FROM table_tabs WHERE id=? AND restaurant_id=? FOR UPDATE');$stmt->execute([$tabId,$vendorId]);$tab=$stmt->fetch();
        if(!$tab||in_array($tab['status'],['closed','cancelled'],true))throw new RuntimeException('This table bill is already closed.');
        if($action==='service_charge'){
            $percent=max(0,min(100,(float)($_POST['percent']??0)));$charge=round((float)$tab['subtotal']*$percent/100,2);
            $pdo->prepare('UPDATE table_tabs SET service_charge=? WHERE id=?')->execute([$charge,$tabId]);$message='Service charge updated.';
        }elseif($action==='discount'){
            $discount=max(0,min((float)$tab['subtotal']+(float)$tab['service_charge'],(float)($_POST['discount']??0)));
            $pdo->prepare('UPDATE table_tabs SET discount=? WHERE id=?')->execute([$discount,$tabId]);$message='Discount updated.';
        }elseif($action==='payment'){
            $amount=round(max(0,(float)($_POST['amount']??0)),2);$tip=round(max(0,(float)($_POST['tip']??0)),2);$method=in_array($_POST['method']??'', ['cash','card','snapscan','zapper','yoco','other'],true)?(string)$_POST['method']:'other';$guestId=filter_input(INPUT_POST,'guest_id',FILTER_VALIDATE_INT)?:null;
            $outstanding=max(0,(float)$tab['total']-(float)$tab['amount_paid']);
            if($amount<=0||$amount>$outstanding+0.01)throw new RuntimeException('Payment must be greater than zero and no more than the outstanding bill.');
            if($guestId){$check=$pdo->prepare('SELECT id FROM tab_guests WHERE id=? AND table_tab_id=?');$check->execute([$guestId,$tabId]);if(!$check->fetchColumn())throw new RuntimeException('The selected person does not belong to this table.');}
            $userId=(int)($_SESSION['user_id']??$_SESSION['staff_user_id']??0)?:null;
            $pdo->prepare("INSERT INTO tab_payments(table_tab_id,tab_guest_id,amount,tip_amount,payment_method,payment_status,paid_at,recorded_by) VALUES(?,?,?,?,?,'paid',NOW(),?)")->execute([$tabId,$guestId,$amount,$tip,$method,$userId]);
            if($tip>0)$pdo->prepare('UPDATE table_tabs SET tip=tip+? WHERE id=?')->execute([$tip,$tabId]);$message='Payment recorded.';
        }elseif($action==='close'){
            refresh_table_tab_totals($pdo,$tabId);$stmt=$pdo->prepare('SELECT total,amount_paid FROM table_tabs WHERE id=?');$stmt->execute([$tabId]);$fresh=$stmt->fetch();
            if((float)$fresh['total']-(float)$fresh['amount_paid']>0.009)throw new RuntimeException('The outstanding balance must be zero before closing the table.');
            $pdo->prepare("UPDATE table_tabs SET status='closed',closed_at=NOW() WHERE id=?")->execute([$tabId]);$message='Table closed and ready for the next party.';
        }
        refresh_table_tab_totals($pdo,$tabId);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}

refresh_table_tab_totals($pdo,$tabId);
$stmt=$pdo->prepare('SELECT tt.*,dt.name table_name FROM table_tabs tt JOIN dining_tables dt ON dt.id=tt.dining_table_id WHERE tt.id=? AND tt.restaurant_id=?');$stmt->execute([$tabId,$vendorId]);$tab=$stmt->fetch();
$stmt=$pdo->prepare("SELECT o.id,o.round_number,o.status,o.created_at,o.total,tg.id guest_id,COALESCE(tg.display_name,o.name,'Shared table') guest_name FROM orders o LEFT JOIN tab_guests tg ON tg.id=o.tab_guest_id WHERE o.table_tab_id=? AND o.status<>'cancelled' ORDER BY o.created_at,o.id");$stmt->execute([$tabId]);$orders=$stmt->fetchAll();$itemsByOrder=[];
if($orders){$ids=array_column($orders,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$stmt=$pdo->prepare("SELECT oi.order_id,oi.quantity,oi.variant_label,oi.item_note,oi.unit_price,COALESCE(oi.item_name,m.name,'Item') item_name FROM order_items oi LEFT JOIN menu_items m ON m.id=oi.menu_item_id WHERE oi.order_id IN ($marks) ORDER BY oi.id");$stmt->execute($ids);foreach($stmt->fetchAll() as $row)$itemsByOrder[(int)$row['order_id']][]=$row;}
$stmt=$pdo->prepare("SELECT tg.id,tg.display_name,COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.total ELSE 0 END),0) subtotal,COALESCE((SELECT SUM(p.amount) FROM tab_payments p WHERE p.tab_guest_id=tg.id AND p.payment_status='paid'),0) paid FROM tab_guests tg LEFT JOIN orders o ON o.tab_guest_id=tg.id WHERE tg.table_tab_id=? GROUP BY tg.id,tg.display_name ORDER BY tg.joined_at");$stmt->execute([$tabId]);$guests=$stmt->fetchAll();
$stmt=$pdo->prepare("SELECT p.*,tg.display_name FROM tab_payments p LEFT JOIN tab_guests tg ON tg.id=p.tab_guest_id WHERE p.table_tab_id=? ORDER BY p.created_at,p.id");$stmt->execute([$tabId]);$payments=$stmt->fetchAll();$outstanding=max(0,(float)$tab['total']-(float)$tab['amount_paid']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($tab['table_name'])?> bill · <?=e($vendor['name'])?></title><link rel="stylesheet" href="/assets/css/super-admin.css?v=20260903-6"></head><body><header class="topbar"><strong><?=e($vendor['name'])?> · Staff</strong><?php vendor_portal_nav($pdo,$vendor,'tables')?><a href="/staff/logout.php?slug=<?=urlencode($slug)?>">Log out</a></header><main class="container"><div class="actions vendor-toolbar"><div><p class="section-kicker">Open table bill</p><h1><?=e($tab['table_name'])?></h1><p class="muted">Opened <?=e($tab['opened_at'])?> · <?=e(ucfirst($tab['status']))?></p></div><strong class="cashup-balance">R<?=number_format($outstanding,2)?> outstanding</strong></div>
<?php if($message):?><div class="notice"><?=e($message)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<div class="cashup-layout"><section><div class="card"><h2>Orders</h2><?php foreach($orders as $order):?><article class="tab-round"><div class="actions"><strong><?=e($order['guest_name'])?> · Round <?=(int)$order['round_number']?></strong><span>R<?=number_format((float)$order['total'],2)?></span></div><ul><?php foreach($itemsByOrder[(int)$order['id']]??[] as $item):?><li><span><?=(int)$item['quantity']?> × <?=e($item['item_name'])?> · <?=e($item['variant_label'])?><?php if($item['item_note']):?><em>Special instruction: <?=e($item['item_note'])?></em><?php endif;?></span><strong>R<?=number_format((float)$item['unit_price']*(int)$item['quantity'],2)?></strong></li><?php endforeach;?></ul></article><?php endforeach;?></div></section>
<aside><section class="card cashup-summary"><h2>Bill summary</h2><dl><div><dt>Food and drinks</dt><dd>R<?=number_format((float)$tab['subtotal'],2)?></dd></div><div><dt>Service charge</dt><dd>R<?=number_format((float)$tab['service_charge'],2)?></dd></div><div><dt>Tips</dt><dd>R<?=number_format((float)$tab['tip'],2)?></dd></div><div><dt>Discount</dt><dd>−R<?=number_format((float)$tab['discount'],2)?></dd></div><div class="cashup-total"><dt>Total</dt><dd>R<?=number_format((float)$tab['total'],2)?></dd></div><div><dt>Paid</dt><dd>R<?=number_format((float)$tab['amount_paid'],2)?></dd></div><div class="cashup-total"><dt>Outstanding</dt><dd>R<?=number_format($outstanding,2)?></dd></div></dl></section>
<?php if(!in_array($tab['status'],['closed','cancelled'],true)):?><form method="post" class="card"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab_id" value="<?=$tabId?>"><input type="hidden" name="action" value="service_charge"><h2>Service charge</h2><label for="percent">Percentage</label><input id="percent" type="number" name="percent" min="0" max="100" step="0.01" value="<?=e((string)$vendor['default_service_charge_percent'])?>"><p><button type="submit">Apply service charge</button></p></form>
<form method="post" class="card"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab_id" value="<?=$tabId?>"><input type="hidden" name="action" value="payment"><h2>Record payment</h2><div class="field"><label for="guest_id">Person / split</label><select id="guest_id" name="guest_id"><option value="">Whole table / custom amount</option><?php foreach($guests as $guest):$guestDue=max(0,(float)$guest['subtotal']-(float)$guest['paid']);?><option value="<?=(int)$guest['id']?>"><?=e($guest['display_name'])?> · R<?=number_format($guestDue,2)?> remaining</option><?php endforeach;?></select></div><div class="field"><label for="amount">Amount toward bill</label><input id="amount" type="number" name="amount" min="0.01" max="<?=number_format($outstanding,2,'.','')?>" step="0.01" value="<?=number_format($outstanding,2,'.','')?>" required></div><div class="field"><label for="tip">Tip</label><input id="tip" type="number" name="tip" min="0" step="0.01" value="0.00"></div><div class="field"><label for="method">Payment method</label><select id="method" name="method"><option value="cash">Cash</option><option value="card">Card</option><option value="snapscan">SnapScan</option><option value="zapper">Zapper</option><option value="yoco">Yoco</option><option value="other">Other</option></select></div><p><button type="submit" <?=$outstanding<=0?'disabled':''?>>Record payment</button></p></form>
<?php if($outstanding<=0):?><form method="post" class="card"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="tab_id" value="<?=$tabId?>"><input type="hidden" name="action" value="close"><button type="submit">Close table</button></form><?php endif;?><?php endif;?></aside></div>
<?php if($payments):?><section class="card"><h2>Payments</h2><div class="payment-history"><?php foreach($payments as $payment):?><div><span><?=e($payment['display_name']?:'Whole table')?> · <?=e(ucfirst($payment['payment_method']))?></span><strong>R<?=number_format((float)$payment['amount']+(float)$payment['tip_amount'],2)?></strong></div><?php endforeach;?></div></section><?php endif;?></main></body></html>
