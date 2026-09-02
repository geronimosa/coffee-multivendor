-- Multi-vendor foundation. Back up the database before applying.

ALTER TABLE restaurants
    ADD COLUMN IF NOT EXISTS slug VARCHAR(100) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS status ENUM('active', 'suspended') NOT NULL DEFAULT 'active' AFTER slug,
    ADD COLUMN IF NOT EXISTS contact_email VARCHAR(255) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(32) NULL AFTER contact_email,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER uid;

UPDATE restaurants
SET slug = CONCAT('vendor-', id)
WHERE slug IS NULL OR slug = '';

ALTER TABLE restaurants
    MODIFY slug VARCHAR(100) NOT NULL,
    ADD UNIQUE KEY IF NOT EXISTS uq_restaurants_slug (slug);

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER active,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER last_login_at;

CREATE TABLE IF NOT EXISTS vendor_integrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vendor_id INT NOT NULL,
    provider VARCHAR(32) NOT NULL,
    environment ENUM('test', 'live') NOT NULL DEFAULT 'test',
    encrypted_config LONGTEXT NOT NULL,
    config_hint VARCHAR(255) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_tested_at DATETIME NULL,
    last_test_status ENUM('untested', 'success', 'failed') NOT NULL DEFAULT 'untested',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vendor_provider (vendor_id, provider),
    CONSTRAINT fk_vendor_integrations_vendor
        FOREIGN KEY (vendor_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    vendor_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id VARCHAR(100) NULL,
    metadata LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_vendor (vendor_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_vendor FOREIGN KEY (vendor_id) REFERENCES restaurants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
