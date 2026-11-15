# cPanel installation

1. Create a MySQL database and database user in cPanel, then grant all required privileges.
2. Upload the repository to a directory outside `public_html` when possible.
3. Set the domain/subdomain document root to the repository's `public/` directory. If your hosting plan cannot change document root, copy only the contents of `public/` into `public_html/` and adjust the bootstrap path in `public/index.php`; keep `app/`, `database/`, `bin/`, `.env`, and `storage/` outside the web root.
4. Copy `.env.example` to `.env`; set database values, `APP_URL`, a random key, and mail connection values from CraneMail.
5. Generate an application key with `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`.
6. Import `database/migrations/001_initial_schema.sql` using phpMyAdmin.
7. Run the administrator bootstrap from Terminal/SSH: `php bin/create-admin.php admin@example.com 'minimum-14-character-password'`. Use `bin/create-member.php` for member accounts.
8. Ensure `.env` is not web-accessible, HTTPS is enabled, and PHP has PDO MySQL, OpenSSL, fileinfo, and the IMAP extension (`imap`) enabled. On cPanel, enable IMAP and fileinfo in Select PHP Version or ask the hosting provider to enable them for the selected PHP version. Set `upload_max_filesize` to at least `25M` and `post_max_size` slightly above `25M` to allow attachments.

Use `MAIL_PROVIDER=manual` until CraneMail confirms its API contract and provides API credentials. Provision mailboxes at CraneMail first, then register them in the admin panel. The first password is displayed once for secure handoff. Open `/mail` after signing in to use the browser webmail. The host must allow outbound SMTP and IMAP connections; configure valid TLS certificates and the exact server hostnames, ports, and encryption modes supplied by CraneMail.
