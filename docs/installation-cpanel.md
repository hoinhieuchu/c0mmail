# cPanel installation and first-run checklist

## 1. Hosting and document root

1. Select PHP 8.3 or newer where available (minimum supported: PHP 8.2).
2. Enable HTTPS before creating the administrator account.
3. Upload the complete repository to a private directory outside `public_html`, for example `/home/ACCOUNT/c0mmail`.
4. Set the domain or subdomain document root to `/home/ACCOUNT/c0mmail/public`. This is the preferred layout: `app/`, `bin/`, `database/`, `docs/`, `.env`, and `tests/` must never be web-accessible.
5. If the hosting plan cannot change the document root, ask the host for a private application directory and a public front-controller mapping. Do not move `.env`, `app/`, `bin/`, or `database/` into a publicly browsable directory.

## 2. PHP requirements

Enable these PHP extensions for the selected domain PHP version:

- `pdo_mysql`
- `imap`
- `openssl`
- `dom` / DOMDocument and XML
- `fileinfo`
- `json`
- `session`

Recommended limits for attachment handling: `upload_max_filesize=25M`, `post_max_size=27M`, `max_file_uploads=10`, and `memory_limit=192M`. Keep `display_errors=Off`, `log_errors=On`, and `expose_php=Off`. The repository includes `public/.user.ini`; some PHP-FPM/hosting configurations require MultiPHP INI Editor instead. Changes to `.user.ini` can take several minutes to apply.

## 3. Database and application configuration

1. Create an empty MariaDB/MySQL database and a dedicated database user. Grant that user privileges on this database only.
2. Copy `.env.example` to `.env` in the repository root (outside the document root).
3. Set `APP_ENV=production`, `APP_DEBUG=false`, the exact HTTPS `APP_URL`, `MAIL_DOMAIN`, and DB credentials.
4. Generate a unique application key:

   ```sh
   php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   ```

   Put the complete output in `APP_KEY`. It must decode to exactly 32 bytes. Keep a secure backup of this key: losing it makes stored mailbox passwords undecryptable.
5. Set SMTP and IMAP hostnames, ports and encryption modes from the mail provider's official setup instructions. Use `ssl` for implicit TLS or `starttls` where the provider explicitly specifies STARTTLS. Do not disable certificate verification to bypass TLS errors.
6. Keep `MAIL_PROVIDER=manual` unless a separately verified provider adapter is available. CraneMail automated provisioning is currently disabled; create mailboxes in the provider control panel first.

## 4. Install schema and administrator

Run these commands from the application root using the same PHP version selected for the website:

```sh
php bin/check-environment.php
php bin/migrate.php
php bin/check-environment.php
php bin/create-admin.php admin@example.com
```

The first preflight can warn that database tables are missing; that is expected for a fresh database. The migration runner applies all five numbered SQL files (including the legacy `002_login_rate_limits.sql`) in filename order and records each completed migration in `schema_migrations`. The second preflight should have no failures. The administrator command prompts for a password without placing it in shell history; use at least 14 characters.

For a database that was imported manually before migration tracking was introduced, take a full backup and verify the schema contains all required tables/columns and the `failed` send-attempt state. Then run `php bin/migrate.php --baseline-existing`. The runner refuses to baseline an incomplete schema. Do not use this flag on a fresh or partially migrated database.

## 5. Provision and test a real mailbox

1. Create the mailbox at the actual mail provider and set its password there.
2. Sign in as administrator, create the member account if required, then register the mailbox address and the same mailbox password in c0m.me.
3. Confirm activation only after the mailbox exists and is active at the provider.
4. Find the mailbox ID in the admin screen/database and run:

   ```sh
   php bin/check-mailbox.php MAILBOX_ID
   ```

   This decrypts the saved mailbox credentials, authenticates to IMAP, lists folders, authenticates to SMTP over verified TLS and issues SMTP NOOP. It does not send a message.
5. Sign in as the mailbox owner, open `/mail`, and send a test email to an address you control. Verify delivery, reply threading, Sent copy, Drafts, read/unread, star, move-to-trash/spam and attachment download.

The hosting provider must allow outbound TCP connections to the configured IMAP and SMTP endpoints. If the check fails, use the exact provider hostname, port and TLS mode and ask the host whether outbound connections are restricted.

## 6. Operations and security

- Keep `.env` outside the document root and restrict filesystem permissions.
- Back up the database and the `.env` application key together. Database backups without the matching `APP_KEY` cannot restore mailbox credentials.
- Do not enable `APP_DEBUG` on a public server. Review PHP/web-server logs rather than displaying exception details to users.
- Restrict administrator accounts and use unique strong passwords.
- To recover an active local account, run `php bin/reset-password.php user@example.com` from the private application directory. Enter the replacement password at the hidden terminal prompt. The command increments the account authentication version and revokes all existing sessions. It is a server-side administrator tool, not an email-based self-service reset flow.
- Test restore procedures before accepting real users.
- This application is a webmail client; it does not provide SMTP/IMAP server infrastructure or operate an MTA.
