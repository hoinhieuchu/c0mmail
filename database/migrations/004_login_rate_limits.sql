CREATE TABLE login_rate_limits (
 key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 window_started_at DATETIME NOT NULL,
 blocked_until DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_login_rate_limits_updated_at (updated_at),
 INDEX idx_login_rate_limits_blocked_until (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
