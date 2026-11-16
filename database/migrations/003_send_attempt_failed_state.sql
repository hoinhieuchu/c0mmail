ALTER TABLE mail_send_attempts
 MODIFY COLUMN status ENUM('pending','sending','accepted','failed','uncertain') NOT NULL DEFAULT 'pending';
