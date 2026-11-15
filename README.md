# c0m.me Mail

Lightweight PHP MVC mail-account management application for cPanel/shared hosting. MariaDB/MySQL via PDO; vanilla JavaScript and CSS.

## Implemented foundation

- Local account authentication, role-based admin access, CSRF protection, and session ID rotation.
- Member-provider abstraction so a future c0m.me Account Center OIDC adapter can replace local identity without rewriting controllers.
- Mail-provider abstraction with manual provisioning and a configurable CraneMail HTTP adapter.
- Mailbox ownership, encrypted-at-rest mailbox passwords, one-time display of the initial password after registration, and manual activation/suspension confirmation.
- Browser webmail using the configured IMAP server: folder discovery, Inbox/message listing, message reading, read/unread and star flags, server-side text search, 50-message pagination, downloading attachments, and moving messages to Trash/Spam folders when available.
- SMTP sending with authenticated SSL/STARTTLS transport, To/Cc/Bcc delivery (Bcc is never exposed in message headers), mailbox display-name encoding, optional validated Reply-To, plain-text and HTML alternatives, and multiple MIME attachments; sent messages are copied to the server's Sent folder when that folder is exposed. Durable database-backed send-attempt records prevent duplicate submissions across sessions and PHP workers; uncertain outcomes after DATA are blocked rather than automatically retried; failures known to occur before DATA are recorded as failed and may be safely retried with the same token. Explicit SMTP 4xx/5xx rejection replies after DATA are also definitive; a missing final reply remains uncertain.
- Draft saving to the server's Drafts folder when available.
- SQL migration, CLI account bootstrap, automated tests, and GitHub Actions PHP lint/test workflow.

The application is a webmail client and mailbox-management layer; it does not run an MTA, SMTP server, or IMAP server. CraneMail remains the email infrastructure provider. Compose supports plain text, optional HTML alternatives, and MIME attachments. IMAP pagination uses older/newer UID cursors scoped to each folder's UIDVALIDITY; the adapter retains offset support for compatibility. Conversation grouping, push/IDLE sync, rich-text editing, and full folder-specific retention policies remain future work.

## Requirements

PHP 8.2+, PDO MySQL, OpenSSL, JSON, sessions, fileinfo, and the PHP IMAP extension (`ext-imap`) for browser webmail and attachment uploads. PHP 8.3+ is recommended for a longer supported security lifecycle. MariaDB 10.5+ or MySQL 8+. cURL is optional.

## Quick start

1. Copy `.env.example` to `.env` and set DB credentials, `APP_URL`, `MAIL_DOMAIN`, SMTP/IMAP values, and a 32-byte random `APP_KEY` encoded as base64.
2. Create a MariaDB/MySQL database and user; import `database/migrations/001_initial_schema.sql`, then `database/migrations/002_mail_send_attempts.sql` and `database/migrations/003_send_attempt_failed_state.sql`.
3. Point the domain document root to `public/`. On cPanel, see [the deployment guide](docs/installation-cpanel.md).
4. Create the first administrator: `php bin/create-admin.php admin@example.com 'A-strong-password-14'`. Create member accounts with `php bin/create-member.php member@example.com 'A-strong-password-14'`.
5. Visit `APP_URL` and sign in.

## Provider modes

- `MAIL_PROVIDER=manual`: safe default. Create the mailbox in CraneMail first, then register it in the admin screen. The application marks it `manual_pending`; confirm activation only after the mailbox exists at CraneMail. Manual suspension likewise requires confirmation after suspension in the provider control panel.
- `MAIL_PROVIDER=cranemail`: enables the HTTP adapter. Configure API base URL, bearer token, and endpoint paths. The adapter contract is described in [architecture](docs/architecture.md). No API credentials are committed. Verify the contract against CraneMail's actual documentation/sandbox before enabling this mode.

## Tests

Run `php tests/run.php` and lint with `find app public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l`. `php tests/database-send-attempts.php` applies all three migrations and exercises the durable send-attempt repository against a MariaDB database (set `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`). `php tests/integration-mail.php` exercises IMAPS and SMTPS against GreenMail on ports 3993 and 3465, plus SMTP STARTTLS against the dedicated Python TLS-upgrade fixture in `tests/fixtures/starttls_smtp_server.py` (default port 3587). The fixture captures the accepted test message and validates SMTP AUTH. GitHub Actions runs lint and the unit suite on PHP 8.2–8.5, runs the database idempotency integration suite against MariaDB, and its PHP 8.5 job provisions GreenMail with a trusted ephemeral certificate and runs all protocol integration checks. GreenMail itself does not implement SMTP STARTTLS (upstream issue: https://github.com/greenmail-mail-test/greenmail/issues/135).

## Security and limitations

Use HTTPS, keep `.env` outside the public web root, use a unique application key, rotate credentials, and restrict admin access. The current release does not include password reset, member self-service registration, mailbox password reset, OIDC, billing, domain verification, rich-text editing, or conversation grouping. Verify the IMAP extension and CraneMail SMTP/IMAP settings on the target host before production launch.

See [architecture](docs/architecture.md) and [cPanel installation](docs/installation-cpanel.md).
