CREATE TABLE users (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 display_name VARCHAR(120) NOT NULL,
 role ENUM('admin','member') NOT NULL DEFAULT 'member',
 status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE mailboxes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 email VARCHAR(254) NOT NULL UNIQUE,
 display_name VARCHAR(120) NOT NULL DEFAULT '',
 provider VARCHAR(32) NOT NULL,
 provider_mailbox_id VARCHAR(190) NULL,
 status ENUM('manual_pending','active','suspended','deleted','error') NOT NULL DEFAULT 'manual_pending',
 password_ciphertext TEXT NULL,
 password_nonce VARCHAR(64) NULL,
 password_tag VARCHAR(64) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_mailboxes_user FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX idx_mailboxes_user (user_id),
 INDEX idx_mailboxes_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
