CREATE TABLE mail_send_attempts (
 submission_id CHAR(32) NOT NULL PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 mailbox_id BIGINT UNSIGNED NOT NULL,
 payload_hash CHAR(64) NULL,
 status ENUM('pending','sending','accepted','uncertain') NOT NULL DEFAULT 'pending',
 message_id VARCHAR(100) NULL,
 sent_folder VARCHAR(255) NULL,
 sent_copy_status ENUM('pending','saved','failed','unavailable') NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_send_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_send_attempts_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE CASCADE,
 INDEX idx_send_attempts_owner (user_id, mailbox_id, created_at),
 INDEX idx_send_attempts_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
