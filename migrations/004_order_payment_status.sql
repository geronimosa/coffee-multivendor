ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid' AFTER total,
    ADD COLUMN IF NOT EXISTS payment_method VARCHAR(32) NULL AFTER payment_status,
    ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL AFTER payment_method,
    ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(191) NULL AFTER paid_at,
    ADD INDEX IF NOT EXISTS idx_orders_payment_status (restaurant_id,payment_status,status);

UPDATE orders
SET payment_status='paid', paid_at=COALESCE(paid_at,created_at)
WHERE status IN ('paid','preparing','complete','collected','archived') AND payment_status='unpaid';
