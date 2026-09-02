CREATE TABLE IF NOT EXISTS edge_staff_access_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vendor_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_edge_staff_key_hash (key_hash),
    KEY idx_edge_staff_vendor_status (vendor_id, status, expires_at),
    KEY idx_edge_staff_vendor_username (vendor_id, username),
    CONSTRAINT fk_edge_staff_vendor FOREIGN KEY (vendor_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_edge_staff_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
