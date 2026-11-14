CREATE TABLE login_attempts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 attempted_at DATETIME NOT NULL,
 INDEX idx_login_attempts_email_ip_time (email_hash,ip_hash,attempted_at),
 INDEX idx_login_attempts_email_time (email_hash,attempted_at),
 INDEX idx_login_attempts_ip_time (ip_hash,attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
