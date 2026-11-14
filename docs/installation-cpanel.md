# cPanel installation

1. Create a MySQL database and database user in cPanel, then grant privileges.
2. Upload the repository outside `public_html` where possible.
3. Set the domain/subdomain document root to the repository's `public/` directory. If the hosting plan cannot change document root, copy only the contents of `public/` into `public_html/` and adjust the bootstrap path; keep `app/`, `database/`, `bin/`, `.env`, and `storage/` outside the web root.
4. Select PHP 8.1+ in MultiPHP Manager. In Select PHP Version / PHP Extensions, enable `imap`, `pdo_mysql`, `openssl`, `fileinfo`, and `mbstring` if available. The application will refuse IMAP operations if the PHP IMAP extension is missing.
5. Copy `.env.example` to `.env`; set database values, `APP_URL`, `MAIL_DOMAIN`, a unique application key, and the SMTP/IMAP host, port, encryption and timeout values supplied by the email provider.
6. Generate the key with `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`.
7. Import `database/migrations/001_initial_schema.sql`, then `database/migrations/002_login_rate_limits.sql` using phpMyAdmin, in that order.
8. Run `php bin/check-requirements.php` from Terminal/SSH and resolve every `[FAIL]`. It checks the PHP runtime, extensions, encryption key, HTTPS and required configuration; it does not test live IMAP/SMTP connectivity.
9. Create the administrator over Terminal/SSH: `php bin/create-admin.php admin@example.com 'minimum-14-character-password'`.
10. Ensure `.env` is not web-accessible, HTTPS is enabled, and PHP upload limits (`upload_max_filesize`, `post_max_size`) are at least the application attachment limits.

## Mail protocol values

Use the exact server names and ports given by the mailbox host. Typical secure defaults are IMAP SSL/TLS port 993 and SMTP STARTTLS port 587, but do not assume these are correct for every provider. `MAIL_IMAP_TIMEOUT` and `MAIL_SMTP_TIMEOUT` bound IMAP/SMTP operations (1–120 seconds). `MAIL_IMAP_ENCRYPTION` and `MAIL_SMTP_ENCRYPTION` accept `ssl`, `starttls`, or `none`; TLS peer and hostname verification is enabled.

The app authenticates to IMAP/SMTP using each mailbox's address and password. It does not run a mail server and does not use a guessed CraneMail HTTP API for message access. Start with a mailbox that has real IMAP/SMTP credentials and verify send/receive before enabling users.

## Attachment limits

Defaults are 10 MiB per attachment, 20 MiB total, and five files. Configure PHP upload limits to be slightly higher than `MAIL_ATTACHMENT_TOTAL_MAX_BYTES` to account for multipart overhead. Downloads are forced as files rather than rendered inline.
