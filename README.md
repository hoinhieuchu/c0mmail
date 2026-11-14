# c0m.me Mail

Lightweight PHP MVC webmail for cPanel/shared hosting. MariaDB/MySQL via PDO; vanilla JavaScript/CSS. CraneMail or another mail provider supplies the actual mailbox infrastructure. This application is an IMAP/SMTP client and does not run an MTA.

## Milestone 1 foundation

Local authentication, admin/member roles, admin UI for creating/suspending/reactivating member accounts, account password changes with current-password verification, a replaceable identity provider interface for future OIDC, mailbox administration, AES-256-GCM encrypted mailbox credentials, manual provisioning, and a configurable (provisional) CraneMail provisioning adapter.

## Milestone 2 webmail engine

- Native PHP IMAP adapter: folder discovery, message listing, UID-based pagination, text search, MIME body/attachment parsing, read/unread and star flags, move, delete/expunge, custom folder creation, APPEND for drafts and Sent copies.
- Socket SMTP adapter: SMTP AUTH LOGIN, implicit TLS or STARTTLS, message delivery, To/CC/BCC, UTF-8 subject/body, MIME attachments and dot-stuffing.
- Provider-neutral IMAP/SMTP interfaces; mailbox passwords are decrypted only for a request and are never stored in the PHP session or logged. The initial manual mailbox password is displayed only in a no-store response after creation.
- Browser UI for folders, message reading, sandboxed HTML email preview with an allowlist sanitizer, compose, reply, forward, CC/BCC, attachments, draft saving and reopening/editing with attachment and private BCC preservation, search and pagination.
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
- `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT`, `MAIL_SMTP_ENCRYPTION`: encryption is `ssl`, `starttls`, or `none`. Auth uses the mailbox email and password. Authenticated SMTP over `none` is blocked by default.
- `MAIL_ALLOW_INSECURE_AUTH`: defaults to `false`; keep it disabled in production. Set to `true` only for an isolated, trusted private test relay that cannot provide TLS.
- `MAIL_FOLDER_INBOX`, `MAIL_FOLDER_SENT`, `MAIL_FOLDER_DRAFTS`, `MAIL_FOLDER_SPAM`, `MAIL_FOLDER_TRASH`: optional exact IMAP folder paths for providers using localized or custom names. Leave Sent/Drafts/Spam/Trash blank to use common-name detection. Set these to the exact server paths (including hierarchy separators) reported by IMAP.
- `MAIL_PAGE_SIZE`: messages per page (maximum 50).
- `MAIL_ATTACHMENT_MAX_BYTES`, `MAIL_ATTACHMENT_TOTAL_MAX_BYTES`: per-file and per-message upload limits.

The app expects the provider to expose standard IMAP and SMTP services. No CraneMail HTTP API is guessed for message operations. The old provisioning HTTP adapter remains disabled unless explicitly configured and verified against official CraneMail documentation.

## Tests

- Unit: `php tests/run.php`
- Database integration: import the migration into MariaDB/MySQL and run `php tests/integration-db.php` with `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` set.
- HTTP smoke: configure a temporary `.env` and run `bash tests/http-smoke.sh` to exercise login, session protection, password changes, dashboard, account, mailbox admin and member management routes.
- Mail integration: start GreenMail with SMTP 3025, IMAP 3143, user `test@example.com` / `secret`, then run `php tests/integration-mail.php`.
- CI starts GreenMail and MariaDB, then runs PHP lint, unit tests, database persistence/credential checks, and real IMAP/SMTP integration tests.

## Security and operational notes

Use HTTPS, keep `.env` outside the web root, use a unique 32-byte app key, and restrict admin access. The application emits a restrictive Content Security Policy and baseline browser security headers. The application does not log mailbox passwords or SMTP/IMAP authentication exchanges. For manual mailboxes, change a mailbox password at the provider first, then use the admin credential-update form to store the new password encrypted in the application. Do not expose downloaded attachments inline. Configure PHP upload/post limits to match the app's attachment limits.

Still outstanding: self-service password reset, inline CID images, background push/IDLE synchronization, provider-specific folder special-use discovery, OAuth2/XOAUTH2 mail auth, multi-tenant mail server credentials, and production verification against CraneMail's real endpoints and certificates. SSO/OIDC remains deliberately out of scope.
