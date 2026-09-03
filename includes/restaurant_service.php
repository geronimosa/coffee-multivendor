<?php
declare(strict_types=1);

function dining_table_by_token(PDO $pdo, int $vendorId, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $stmt=$pdo->prepare("SELECT * FROM dining_tables WHERE restaurant_id=? AND qr_token=? AND status='active' LIMIT 1");
    $stmt->execute([$vendorId,$token]);
    return $stmt->fetch() ?: null;
}

function open_table_tab(PDO $pdo, int $vendorId, int $tableId): array
{
    $stmt=$pdo->prepare("SELECT * FROM table_tabs WHERE restaurant_id=? AND dining_table_id=? AND status IN ('open','settlement') ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([$vendorId,$tableId]);
    $tab=$stmt->fetch();
    if ($tab) return $tab;
    $token=bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO table_tabs(restaurant_id,dining_table_id,tab_token) VALUES(?,?,?)')->execute([$vendorId,$tableId,$token]);
    $stmt=$pdo->prepare('SELECT * FROM table_tabs WHERE id=?');$stmt->execute([(int)$pdo->lastInsertId()]);
    return $stmt->fetch();
}

function refresh_table_tab_totals(PDO $pdo, int $tabId): void
{
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE table_tab_id=? AND status<>'cancelled'");$stmt->execute([$tabId]);
    $subtotal=(float)$stmt->fetchColumn();
    $stmt=$pdo->prepare("SELECT COALESCE(SUM(amount+tip_amount),0) FROM tab_payments WHERE table_tab_id=? AND payment_status='paid'");$stmt->execute([$tabId]);$paid=(float)$stmt->fetchColumn();
    $pdo->prepare("UPDATE table_tabs SET subtotal=?,total=GREATEST(0,?+service_charge+tip-discount),amount_paid=?,status=CASE WHEN status IN ('closed','cancelled') THEN status WHEN ?>=GREATEST(0,?+service_charge+tip-discount) AND GREATEST(0,?+service_charge+tip-discount)>0 THEN 'paid' ELSE 'open' END WHERE id=?")->execute([$subtotal,$subtotal,$paid,$paid,$subtotal,$subtotal,$tabId]);
}
