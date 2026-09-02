<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_super_admin();

$vendors = $pdo->query(
    "SELECT r.id, r.name, r.slug, r.status, r.contact_email,
       MAX(CASE WHEN vi.provider='yoco' THEN vi.config_hint END) AS yoco_hint,
       MAX(CASE WHEN vi.provider='twilio' THEN vi.config_hint END) AS twilio_hint
     FROM restaurants r LEFT JOIN vendor_integrations vi ON vi.vendor_id=r.id
     GROUP BY r.id ORDER BY r.name"
)->fetchAll();
$pageTitle = 'Vendors';
require __DIR__ . '/_header.php';
?>
<div class="actions"><div><h1>Vendors</h1><p class="muted">Create and configure independent kiosk owners.</p></div><a class="button" href="vendor_edit.php">Add vendor</a></div>
<section class="card">
<table><thead><tr><th>Vendor</th><th>Status</th><th>Yoco</th><th>WhatsApp</th><th>Services</th><th></th></tr></thead><tbody>
<?php foreach ($vendors as $vendor): ?>
<tr>
    <td><strong><?= e($vendor['name']) ?></strong><br><span class="muted"><?= e($vendor['slug']) ?></span></td>
    <td><span class="badge <?= $vendor['status'] === 'suspended' ? 'suspended' : '' ?>"><?= e(ucfirst($vendor['status'])) ?></span></td>
    <td><?= e($vendor['yoco_hint'] ?: 'Not configured') ?></td>
    <td><?= e($vendor['twilio_hint'] ?: 'Not configured') ?></td>
    <td class="actions"><a href="/shop/<?= e($vendor['slug']) ?>" target="_blank">Open shop</a><a href="/vendor/<?= e($vendor['slug']) ?>" target="_blank">Staff portal</a></td><td><a href="vendor_edit.php?id=<?= (int) $vendor['id'] ?>">Edit</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$vendors): ?><tr><td colspan="6">No vendors yet.</td></tr><?php endif; ?>
</tbody></table></section>
<?php require __DIR__ . '/_footer.php'; ?>
