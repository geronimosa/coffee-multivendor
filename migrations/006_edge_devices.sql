CREATE TABLE IF NOT EXISTS edge_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vendor_id INT NOT NULL,
    device_identifier CHAR(32) NOT NULL,
    device_name VARCHAR(120) NULL,
    credential_hash CHAR(64) NOT NULL,
    encrypted_credential LONGTEXT NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    software_version VARCHAR(50) NULL,
    last_snapshot_hash CHAR(64) NULL,
    last_seen_at DATETIME NULL,
    provisioned_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_edge_vendor (vendor_id),
    UNIQUE KEY uq_edge_identifier (device_identifier),
    KEY idx_edge_status_seen (status, last_seen_at),
    CONSTRAINT fk_edge_device_vendor FOREIGN KEY (vendor_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS edge_enrollment_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vendor_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_edge_enrollment_hash (token_hash),
    KEY idx_edge_enrollment_vendor (vendor_id, expires_at),
    CONSTRAINT fk_edge_enrollment_vendor FOREIGN KEY (vendor_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_edge_enrollment_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
