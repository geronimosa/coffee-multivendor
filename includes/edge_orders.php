<?php
declare(strict_types=1);

function edge_sync_string(mixed $value, int $maxLength, bool $required = false): ?string
{
    if ($value === null && !$required) return null;
    $value = trim((string) $value);
    if (($required && $value === '') || mb_strlen($value) > $maxLength) {
        throw new InvalidArgumentException('Invalid Edge order text value.');
    }
    return $value;
}

function edge_sync_datetime(mixed $value): string
{
    try {
        $date = new DateTimeImmutable((string) $value);
    } catch (Throwable) {
        throw new InvalidArgumentException('Invalid Edge order timestamp.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function edge_sync_decimal(mixed $value): string
{
    $value = (string) $value;
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Invalid Edge order amount.');
    }
    return number_format((float) $value, 2, '.', '');
}

function sync_edge_orders(PDO $pdo, array $device, array $input): array
{
    $orders = $input['orders'] ?? null;
    if (!is_array($orders) || count($orders) > 50) {
        throw new InvalidArgumentException('Invalid Edge order batch.');
    }
    $acceptedOrders = [];
    $acceptedEvents = [];
    $statuses = ['pending', 'preparing', 'complete', 'collected', 'archived', 'cancelled'];
    $paymentStatuses = ['unpaid', 'paid', 'refunded'];

    $pdo->beginTransaction();
    try {
        foreach ($orders as $order) {
            if (!is_array($order) || !preg_match('/^[a-f0-9-]{36}$/i', (string) ($order['order_uuid'] ?? ''))) {
                throw new InvalidArgumentException('Invalid Edge order identity.');
            }
            $orderUuid = strtolower((string) $order['order_uuid']);
            $status = (string) ($order['status'] ?? '');
            $paymentStatus = (string) ($order['payment_status'] ?? '');
            if (!in_array($status, $statuses, true) || !in_array($paymentStatus, $paymentStatuses, true)) {
                throw new InvalidArgumentException('Invalid Edge order state.');
            }
            $items = $order['items'] ?? null;
            $events = $order['events'] ?? [];
            if (!is_array($items) || !$items || count($items) > 100 || !is_array($events) || count($events) > 100) {
                throw new InvalidArgumentException('Invalid Edge order contents.');
            }
            $total = edge_sync_decimal($order['total'] ?? null);
            $calculatedCents = 0;
            foreach ($items as $item) {
                if (!is_array($item) || (int) ($item['origin_line_id'] ?? 0) < 1 || (int) ($item['quantity'] ?? 0) < 1 || (int) $item['quantity'] > 100) {
                    throw new InvalidArgumentException('Invalid Edge order line.');
                }
                $unitPrice = edge_sync_decimal($item['unit_price'] ?? null);
                $calculatedCents += (int) round((float) $unitPrice * 100) * (int) $item['quantity'];
                edge_sync_string($item['item_name'] ?? null, 255, true);
                edge_sync_string($item['variant_label'] ?? null, 100);
            }
            if ($calculatedCents !== (int) round((float) $total * 100)) {
                throw new InvalidArgumentException('Edge order total does not match its lines.');
            }
            $sourceUpdatedAt = edge_sync_datetime($order['updated_at'] ?? null);
            $createdAt = edge_sync_datetime($order['created_at'] ?? null);
            $customerName = edge_sync_string($order['customer_name'] ?? null, 100);
            $customerPhone = edge_sync_string($order['customer_phone'] ?? null, 20);
            $statusToken = edge_sync_string($order['status_token'] ?? null, 64, true);
            $paymentMethod = edge_sync_string($order['payment_method'] ?? null, 32);
            $paymentReference = edge_sync_string($order['payment_reference'] ?? null, 191);
            $paidAt = empty($order['paid_at']) ? null : edge_sync_datetime($order['paid_at']);

            $stmt = $pdo->prepare('SELECT id,restaurant_id,origin_device_identifier,source_updated_at FROM orders WHERE order_uuid=? LIMIT 1 FOR UPDATE');
            $stmt->execute([$orderUuid]);
            $central = $stmt->fetch();
            if ($central && ((int) $central['restaurant_id'] !== (int) $device['vendor_id'] || !hash_equals((string) $central['origin_device_identifier'], (string) $device['device_identifier']))) {
                throw new InvalidArgumentException('Edge order ownership conflict.');
            }
            if (!$central) {
                $stmt = $pdo->prepare(
                    "INSERT INTO orders (order_uuid,origin_type,origin_device_identifier,source_updated_at,restaurant_id,status,token,total,payment_status,payment_method,paid_at,payment_reference,created_at,name,phone,credit_card_payment)
                     VALUES (?,'edge',?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                $stmt->execute([
                    $orderUuid, $device['device_identifier'], $sourceUpdatedAt, $device['vendor_id'], $status,
                    $statusToken, $total, $paymentStatus, $paymentMethod, $paidAt, $paymentReference,
                    $createdAt, $customerName, $customerPhone, $paymentMethod === 'manual' ? 1 : 0,
                ]);
                $orderId = (int) $pdo->lastInsertId();
            } else {
                $orderId = (int) $central['id'];
                if ($central['source_updated_at'] === null || strcmp($sourceUpdatedAt, (string) $central['source_updated_at']) >= 0) {
                    $stmt = $pdo->prepare(
                        'UPDATE orders SET source_updated_at=?,status=?,total=?,payment_status=?,payment_method=?,paid_at=?,payment_reference=?,name=?,phone=?,credit_card_payment=? WHERE id=?'
                    );
                    $stmt->execute([
                        $sourceUpdatedAt, $status, $total, $paymentStatus, $paymentMethod, $paidAt,
                        $paymentReference, $customerName, $customerPhone, $paymentMethod === 'manual' ? 1 : 0, $orderId,
                    ]);
                }
            }

            foreach ($items as $item) {
                $menuItemId = (int) ($item['remote_menu_item_id'] ?? 0);
                $menuCheck = $pdo->prepare('SELECT id FROM menu_items WHERE id=? AND restaurant_id=?');
                $menuCheck->execute([$menuItemId, $device['vendor_id']]);
                $menuItemId = $menuCheck->fetchColumn() ? $menuItemId : null;
                $stmt = $pdo->prepare(
                    'INSERT IGNORE INTO order_items (order_id,origin_line_id,menu_item_id,item_name,variant_label,item_note,unit_price,quantity) VALUES (?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $orderId, (int) $item['origin_line_id'], $menuItemId,
                    edge_sync_string($item['item_name'], 255, true), edge_sync_string($item['variant_label'] ?? null, 100), edge_sync_string($item['item_note'] ?? null, 250),
                    edge_sync_decimal($item['unit_price']), (int) $item['quantity'],
                ]);
            }

            foreach ($events as $event) {
                if (!is_array($event) || !preg_match('/^[a-f0-9-]{36}$/i', (string) ($event['event_uuid'] ?? ''))) {
                    throw new InvalidArgumentException('Invalid Edge event identity.');
                }
                $eventUuid = strtolower((string) $event['event_uuid']);
                $eventStatus = (string) ($event['payment_status'] ?? '');
                if (!in_array($eventStatus, $paymentStatuses, true)) {
                    throw new InvalidArgumentException('Invalid Edge event state.');
                }
                $staffKeyId = (int) ($event['staff_key_id'] ?? 0);
                $staffCheck = $pdo->prepare('SELECT id FROM edge_staff_access_keys WHERE id=? AND vendor_id=?');
                $staffCheck->execute([$staffKeyId, $device['vendor_id']]);
                $staffKeyId = $staffCheck->fetchColumn() ? $staffKeyId : null;
                $stmt = $pdo->prepare(
                    'INSERT IGNORE INTO edge_order_events (event_uuid,order_id,edge_staff_key_id,actor_username,action,from_status,to_status,payment_status,occurred_at) VALUES (?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $eventUuid, $orderId, $staffKeyId,
                    edge_sync_string($event['actor_username'] ?? null, 50, true),
                    edge_sync_string($event['action'] ?? null, 32, true),
                    edge_sync_string($event['from_status'] ?? null, 20),
                    edge_sync_string($event['to_status'] ?? null, 20),
                    $eventStatus, edge_sync_datetime($event['created_at'] ?? null),
                ]);
                $acceptedEvents[] = $eventUuid;
            }
            $acceptedOrders[] = $orderUuid;
        }

        $field = !empty($input['reconcile']) ? 'last_reconciliation_at' : 'last_order_sync_at';
        $pdo->prepare("UPDATE edge_devices SET {$field}=NOW(),last_order_sync_at=NOW(),last_seen_at=NOW() WHERE id=?")
            ->execute([$device['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    return ['orders' => $acceptedOrders, 'events' => $acceptedEvents];
}
