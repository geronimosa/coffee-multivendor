<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_super_admin();

$vendors = $pdo->query(
    "SELECT r.id, r.name, r.slug, r.status, r.service_model, r.contact_email,
       MAX(CASE WHEN vi.provider='yoco' THEN vi.config_hint END) AS yoco_hint,
       MAX(CASE WHEN vi.provider='snapscan' THEN vi.config_hint END) AS snapscan_hint,
       MAX(CASE WHEN vi.provider='zapper' THEN vi.config_hint END) AS zapper_hint,
       MAX(CASE WHEN vi.provider='twilio' THEN vi.config_hint END) AS twilio_hint
     FROM restaurants r LEFT JOIN vendor_integrations vi ON vi.vendor_id=r.id
     GROUP BY r.id ORDER BY r.name"
)->fetchAll();
$pageTitle = 'Vendors';
require __DIR__ . '/_header.php';
?>
<div class="actions vendor-toolbar"><div><h1>Vendors</h1><p class="muted">Create and configure independent kiosk owners.</p></div><a class="button" href="vendor_edit.php">Add vendor</a></div>
<section class="card vendor-list">
<table class="vendor-table"><thead><tr><th>Vendor</th><th>Status</th><th>Payments</th><th>Messaging</th><th>Access</th></tr></thead><tbody>
<?php foreach ($vendors as $vendor): ?>
<tr>
    <td data-label="Vendor"><strong class="vendor-name"><?= e($vendor['name']) ?></strong><br><small class="muted"><?=($vendor['service_model']??'kiosk')==='restaurant'?'Restaurant / table service':'Kiosk / food truck'?></small></td>
    <td data-label="Status"><span class="badge <?= $vendor['status'] === 'suspended' ? 'suspended' : '' ?>"><?= e(ucfirst($vendor['status'])) ?></span></td>
    <td data-label="Payments"><div class="integration-stack"><span class="integration-state <?= $vendor['yoco_hint'] ? 'configured' : '' ?>">Yoco</span><span class="integration-state <?= $vendor['snapscan_hint'] ? 'configured' : '' ?>">SnapScan</span><span class="integration-state <?= $vendor['zapper_hint'] ? 'configured' : '' ?>">Zapper</span></div></td>
    <td data-label="Messaging"><span class="integration-state <?= $vendor['twilio_hint'] ? 'configured' : '' ?>"><?= e($vendor['twilio_hint'] ?: 'Twilio not configured') ?></span></td>
    <td data-label="Access"><div class="vendor-actions"><?php if(($vendor['service_model']??'kiosk')==='kiosk'):?><a class="button secondary" href="/shop/<?= e($vendor['slug']) ?>" target="_blank">Shop</a><?php else:?><a class="button secondary" href="/shop/<?=e($vendor['slug'])?>/takeaway" target="_blank">Takeaway</a><a class="button secondary" href="/vendor/<?=e($vendor['slug'])?>/tables">Tables</a><?php endif;?><a class="button secondary" href="/vendor/<?= e($vendor['slug']) ?>" target="_blank">Staff</a><a class="button secondary" href="/vendor/<?=e($vendor['slug'])?>/reports">Reports</a><a class="button secondary" href="/product/<?= e($vendor['slug']) ?>">Products</a><a class="button secondary" href="edge_device.php?id=<?= (int) $vendor['id'] ?>">Edge</a><a class="button" href="vendor_edit.php?id=<?= (int) $vendor['id'] ?>">Edit</a></div></td>
</tr>
<?php endforeach; ?>
<?php if (!$vendors): ?><tr><td colspan="5">No vendors yet.</td></tr><?php endif; ?>
</tbody></table></section>
<?php require __DIR__ . '/_footer.php'; ?>
