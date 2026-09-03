-- Restaurant/table-service foundation. Existing vendors remain kiosk vendors.
ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS service_model ENUM('kiosk','restaurant') NOT NULL DEFAULT 'kiosk' AFTER status,
    ADD COLUMN IF NOT EXISTS default_service_charge_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER service_model;

CREATE TABLE IF NOT EXISTS dining_tables (
    id INT NOT NULL AUTO_INCREMENT,
    restaurant_id INT NOT NULL,
    name VARCHAR(80) NOT NULL,
    area VARCHAR(80) NULL,
    capacity SMALLINT UNSIGNED NULL,
    qr_token CHAR(32) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dining_table_token (qr_token),
    UNIQUE KEY uq_dining_table_name (restaurant_id,name),
    CONSTRAINT fk_dining_table_vendor FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS table_tabs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id INT NOT NULL,
    dining_table_id INT NOT NULL,
    tab_token CHAR(32) NOT NULL,
    status ENUM('open','settlement','paid','closed','cancelled') NOT NULL DEFAULT 'open',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tip DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_table_tab_token (tab_token),
    KEY idx_table_tab_active (dining_table_id,status),
    CONSTRAINT fk_table_tab_vendor FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_table_tab_table FOREIGN KEY (dining_table_id) REFERENCES dining_tables(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tab_guests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_tab_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(100) NOT NULL DEFAULT 'Guest',
    seat_number VARCHAR(20) NULL,
    guest_token CHAR(32) NOT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tab_guest_token (guest_token),
    KEY idx_tab_guests_tab (table_tab_id),
    CONSTRAINT fk_tab_guest_tab FOREIGN KEY (table_tab_id) REFERENCES table_tabs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS table_tab_id BIGINT UNSIGNED NULL AFTER restaurant_id,
    ADD COLUMN IF NOT EXISTS tab_guest_id BIGINT UNSIGNED NULL AFTER table_tab_id,
    ADD COLUMN IF NOT EXISTS service_type ENUM('kiosk','table') NOT NULL DEFAULT 'kiosk' AFTER tab_guest_id,
    ADD COLUMN IF NOT EXISTS round_number SMALLINT UNSIGNED NULL AFTER service_type,
    ADD INDEX IF NOT EXISTS idx_orders_table_tab (table_tab_id,created_at),
    ADD CONSTRAINT fk_order_table_tab FOREIGN KEY (table_tab_id) REFERENCES table_tabs(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_order_tab_guest FOREIGN KEY (tab_guest_id) REFERENCES tab_guests(id) ON DELETE SET NULL;

ALTER TABLE order_items
    ADD COLUMN IF NOT EXISTS item_note VARCHAR(250) NULL AFTER variant_label;

CREATE TABLE IF NOT EXISTS tab_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_tab_id BIGINT UNSIGNED NOT NULL,
    tab_guest_id BIGINT UNSIGNED NULL,
    amount DECIMAL(10,2) NOT NULL,
    tip_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(32) NOT NULL,
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    provider_reference VARCHAR(191) NULL,
    paid_at DATETIME NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tab_payment_tab (table_tab_id,payment_status),
    CONSTRAINT fk_tab_payment_tab FOREIGN KEY (table_tab_id) REFERENCES table_tabs(id) ON DELETE CASCADE,
    CONSTRAINT fk_tab_payment_guest FOREIGN KEY (tab_guest_id) REFERENCES tab_guests(id) ON DELETE SET NULL,
    CONSTRAINT fk_tab_payment_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
