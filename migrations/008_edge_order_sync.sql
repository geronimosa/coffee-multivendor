ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS order_uuid CHAR(36) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS origin_type ENUM('cloud','edge') NOT NULL DEFAULT 'cloud' AFTER order_uuid,
    ADD COLUMN IF NOT EXISTS origin_device_identifier CHAR(32) NULL AFTER origin_type,
    ADD COLUMN IF NOT EXISTS source_updated_at DATETIME NULL AFTER origin_device_identifier,
    ADD UNIQUE KEY IF NOT EXISTS uq_orders_order_uuid (order_uuid),
    ADD INDEX IF NOT EXISTS idx_orders_origin_device (origin_device_identifier, source_updated_at);

ALTER TABLE order_items
    DROP FOREIGN KEY IF EXISTS order_items_ibfk_2,
    MODIFY menu_item_id INT NULL,
    ADD COLUMN IF NOT EXISTS origin_line_id BIGINT NULL AFTER id,
    ADD COLUMN IF NOT EXISTS item_name VARCHAR(255) NULL AFTER menu_item_id,
    ADD UNIQUE KEY IF NOT EXISTS uq_order_items_origin_line (order_id, origin_line_id),
    ADD CONSTRAINT fk_order_items_menu_item FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS edge_order_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_uuid CHAR(36) NOT NULL,
    order_id INT NOT NULL,
    edge_staff_key_id BIGINT UNSIGNED NULL,
    actor_username VARCHAR(50) NOT NULL,
    action VARCHAR(32) NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NULL,
    payment_status VARCHAR(20) NOT NULL,
    occurred_at DATETIME NOT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_edge_order_event_uuid (event_uuid),
    KEY idx_edge_order_event_order (order_id, occurred_at),
    CONSTRAINT fk_edge_order_event_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_edge_order_event_staff FOREIGN KEY (edge_staff_key_id) REFERENCES edge_staff_access_keys(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE edge_devices
    ADD COLUMN IF NOT EXISTS last_order_sync_at DATETIME NULL AFTER last_seen_at,
    ADD COLUMN IF NOT EXISTS last_reconciliation_at DATETIME NULL AFTER last_order_sync_at;
