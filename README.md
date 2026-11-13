# c0m.me Mail

Lightweight PHP MVC webmail for cPanel/shared hosting. MariaDB/MySQL via PDO; vanilla JavaScript/CSS. CraneMail or another mail provider supplies the actual mailbox infrastructure. This application is an IMAP/SMTP client and does not run an MTA.

## Milestone 1 foundation

Local authentication, admin/member roles, a replaceable identity provider interface for future OIDC, mailbox administration, AES-256-GCM encrypted mailbox credentials, manual provisioning, and a configurable (provisional) CraneMail provisioning adapter.

## Milestone 2 webmail engine

- Native PHP IMAP adapter: folder discovery, message listing, UID-based pagination, text search, MIME body/attachment parsing, read/unread and star flags, move, delete/expunge, custom folder creation, APPEND for drafts and Sent copies.
- Socket SMTP adapter: SMTP AUTH LOGIN, implicit TLS or STARTTLS, message delivery, To/CC/BCC, UTF-8 subject/body, MIME attachments and dot-stuffing.
- Provider-neutral IMAP/SMTP interfaces; mailbox passwords are decrypted only for a request and are never stored in the PHP session or logged.
- Browser UI for folders, message reading, compose, reply, forward, CC/BCC, attachments, draft saving, search and pagination.
- Attachment upload defaults: 10 MiB per file, 20 MiB total, maximum five files. Executable/script/web page file extensions are blocked. Downloads are forced as attachments.

## Requirements

PHP 8.1+ with PDO MySQL, OpenSSL, IMAP extension, fileinfo and sessions. MariaDB 10.5+ or MySQL 8+. SMTP and IMAP hostnames, ports, encryption modes and mailbox credentials must be supplied by the mail provider.

## Setup

1. Copy `.env.example` to `.env`. Configure DB, `APP_URL`, `MAIL_DOMAIN`, `APP_KEY`, and SMTP/IMAP transport settings.
2. Generate the app key: `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`.
3. Import `database/migrations/001_initial_schema.sql`.
4. Point the domain document root to `public/`; follow [cPanel installation](docs/installation-cpanel.md).
5. Create an administrator using `php bin/create-admin.php admin@example.com 'A-strong-password-14'`.

## Mail transport configuration

- `MAIL_IMAP_HOST`, `MAIL_IMAP_PORT`, `MAIL_IMAP_ENCRYPTION`: encryption is `ssl`, `starttls`, or `none`; certificate verification is enabled for TLS.
- `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT`, `MAIL_SMTP_ENCRYPTION`: encryption is `ssl`, `starttls`, or `none`. Auth uses the mailbox email and password.
- `MAIL_PAGE_SIZE`: messages per page (maximum 50).
- `MAIL_ATTACHMENT_MAX_BYTES`, `MAIL_ATTACHMENT_TOTAL_MAX_BYTES`: per-file and per-message upload limits.

The app expects the provider to expose standard IMAP and SMTP services. No CraneMail HTTP API is guessed for message operations. The old provisioning HTTP adapter remains disabled unless explicitly configured and verified against official CraneMail documentation.

## Tests

- Unit: `php tests/run.php`
- Real integration: start GreenMail with SMTP 3025, IMAP 3143, user `test@example.test` / `secret`, then run `php tests/integration-mail.php`.
- CI starts GreenMail and runs PHP lint, unit tests, and real IMAP/SMTP integration tests.

## Security and operational notes

Use HTTPS, keep `.env` outside the web root, use a unique 32-byte app key, and restrict admin access. The application does not log mailbox passwords or SMTP/IMAP authentication exchanges. Rotate a mailbox password at the provider and update it in the encrypted mailbox record when changing credentials. Do not expose downloaded attachments inline. Configure PHP upload/post limits to match the app's attachment limits.

Still outstanding: password change/reset UI, robust HTML email sanitization/rendering, inline images, background push/IDLE synchronization, provider-specific folder special-use discovery, OAuth2/XOAUTH2 mail auth, multi-tenant mail server credentials, and production verification against CraneMail's real endpoints and certificates. SSO/OIDC remains deliberately out of scope.
