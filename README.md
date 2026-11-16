# c0m.me Mail

Lightweight PHP MVC mail-account management application for cPanel/shared hosting. MariaDB/MySQL via PDO; vanilla JavaScript and CSS.

## Implemented foundation

- Local account authentication, role-based admin access, CSRF protection, and session ID rotation. Login attempts are throttled persistently by account and client IP; forwarded IP headers are accepted only from explicitly configured trusted proxy CIDRs.
- Member-provider abstraction so a future c0m.me Account Center OIDC adapter can replace local identity without rewriting controllers.
- Mail-provider abstraction with manual provisioning and a configurable CraneMail HTTP adapter.
- Mailbox ownership, encrypted-at-rest mailbox passwords, one-time display of the initial password after registration, and manual activation/suspension confirmation.
- Conversation grouping uses RFC Message-ID/References/In-Reply-To relationships for the current 50-message page; opening a thread exposes its related messages from that page. It does not merge by subject alone.
- Webmail checks the selected IMAP folder for new messages every 90 seconds while the page is visible; the check is session-throttled and offers a manual refresh without interrupting an in-progress composition. This is polling, not IMAP IDLE push.
- Browser webmail using the configured IMAP server: folder discovery, Inbox/message listing, message reading, read/unread and star flags, server-side text search, 50-message pagination, downloading attachments, and moving messages to Trash/Spam folders when available.
- SMTP sending with authenticated SSL/STARTTLS transport, To/Cc/Bcc delivery (Bcc is never exposed in message headers), mailbox display-name encoding, optional validated Reply-To, plain-text and HTML alternatives, and multiple MIME attachments; sent messages are copied to the server's Sent folder when that folder is exposed. Durable database-backed send-attempt records prevent duplicate submissions across sessions and PHP workers; uncertain outcomes after DATA are blocked rather than automatically retried; failures known to occur before DATA are recorded as failed and may be safely retried with the same token. Explicit SMTP 4xx/5xx rejection replies after DATA are also definitive; a missing final reply remains uncertain.
- Draft saving to the server's Drafts folder when available.
- Rich-text compose with bold, italic, underline, lists, and links; outbound HTML is sanitized server-side, with unsafe tags, attributes, embedded media, and URL schemes removed.
- SQL migration, CLI account bootstrap, automated tests, and GitHub Actions PHP lint/test workflow.

The application is a webmail client and mailbox-management layer; it does not run an MTA, SMTP server, or IMAP server. CraneMail remains the email infrastructure provider. Compose supports plain text, rich-text HTML alternatives with server-side allow-list sanitization, and MIME attachments. IMAP pagination uses older/newer UID cursors scoped to each folder's UIDVALIDITY; the adapter retains offset support for compatibility. Current-page RFC conversation grouping is implemented; full-folder thread aggregation and persistent IMAP IDLE/push synchronization remain future work.

## Requirements

PHP 8.2+, PDO MySQL, OpenSSL, DOM/XML, JSON, sessions, fileinfo, and the PHP IMAP extension (`ext-imap`) for browser webmail and attachment uploads. PHP 8.3+ is recommended for a longer supported security lifecycle. MariaDB 10.5+ or MySQL 8+. cURL is optional.

## Quick start (fresh installation)

1. Upload the repository outside `public_html` and set the website document root to the repository's `public/` directory. Enable HTTPS.
2. Create an empty MariaDB/MySQL database and database user in cPanel.
3. Copy `.env.example` to `.env`. Set `APP_URL`, a unique 32-byte random `APP_KEY`, database credentials, `MAIL_DOMAIN`, and the exact SMTP/IMAP host, port and TLS mode supplied by your mail provider. Keep `.env` outside the document root.
4. Run `php bin/check-environment.php` to check PHP extensions and configuration. Missing database tables are expected before the next step.
5. Run `php bin/migrate.php` to create and record the database schema. The runner applies numbered SQL files in order and skips migrations already recorded in `schema_migrations`.
6. Run `php bin/check-environment.php` again and resolve every `[FAIL]` item. Warnings for unset SMTP/IMAP hosts mean real webmail is not ready yet.
7. Create the first administrator with `php bin/create-admin.php admin@example.com`; enter the password at the hidden terminal prompt. Create members with `php bin/create-member.php member@example.com` (also hidden input).
8. Sign in, create the mailbox at your mail provider, register it in the admin panel, and activate it only after provider-side creation is complete.
9. Run `php bin/check-mailbox.php MAILBOX_ID`. It authenticates to IMAP, lists folders, authenticates to SMTP over verified TLS and issues NOOP; it does not send a message.
10. Open `/mail`, send a test message to an address you control, and verify Inbox, Sent, Drafts, attachments and flags.

For an existing database imported manually before the migration runner existed, take a backup and verify all five current migration files are present before running `php bin/migrate.php --baseline-existing`. The command checks the required tables/columns and the SMTP failed state before recording the baseline. Never run the baseline flag against a partially migrated database.

## Mail provider configuration

- `MAIL_PROVIDER=manual` is the supported deployment mode. Create each mailbox in your actual mail provider first, then register the same address and password in the c0m.me admin panel. The mailbox stays `manual_pending` until an administrator confirms that provider-side provisioning is complete.
- SMTP and IMAP connectivity is configured separately with `MAIL_SMTP_HOST`, `MAIL_SMTP_PORT`, `MAIL_SMTP_ENCRYPTION`, `MAIL_IMAP_HOST`, `MAIL_IMAP_PORT`, and `MAIL_IMAP_ENCRYPTION`. Use the exact hostnames and TLS modes from your provider; do not guess these values.
- `MAIL_PROVIDER=cranemail` is not production-ready: automated provisioning/suspension/deletion deliberately throws until CraneMail supplies a verifiable API specification and credentials. Do not enable it for live users.

## Tests

Run `php tests/run.php` (66 unit tests) and lint with `find app public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l`. `php tests/database-send-attempts.php` applies all five migrations and exercises durable send-attempt persistence plus account/IP login throttling against a MariaDB database (set `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`). `php tests/integration-mail.php` exercises IMAPS and SMTPS against GreenMail on ports 3993 and 3465, plus SMTP STARTTLS against the dedicated Python TLS-upgrade fixture in `tests/fixtures/starttls_smtp_server.py` (default port 3587). The fixtures capture accepted test messages, validate SMTP AUTH, and verify that an explicit 5xx after DATA is definitive while a lost final reply after DATA remains uncertain. The browser sync poller pauses while tabs are hidden or offline, resumes on visibility/network restoration, applies exponential retry backoff and honours both Retry-After formats, and updates its notice without duplicating links. Run `node --test tests/webmail-sync.test.js` for its five polling-behaviour checks. GitHub Actions runs lint and the unit suite on PHP 8.2–8.5, runs the database idempotency integration suite against MariaDB, and its PHP 8.5 job provisions GreenMail with a trusted ephemeral certificate and runs all protocol integration checks. GreenMail itself does not implement SMTP STARTTLS (upstream issue: https://github.com/greenmail-mail-test/greenmail/issues/135).

## Security and limitations

Use HTTPS, keep `.env` outside the public web root, use a unique application key, rotate credentials, and restrict admin access. The current release does not include password reset, member self-service registration, mailbox password reset, OIDC, billing, domain verification, password-reset email, or conversation grouping across more than the nearest 2,000 folder UIDs (the selected-message conversation panel scans a bounded set of folder summaries). Verify the IMAP extension and CraneMail SMTP/IMAP settings on the target host before production launch.

Deployment helpers: `bin/check-environment.php` (preflight), `bin/migrate.php` (ordered database migrations), and `bin/check-mailbox.php ID` (live IMAP/SMTP authentication test without sending mail). See [architecture](docs/architecture.md), [cPanel installation](docs/installation-cpanel.md), and the [backup/restore runbook](docs/backup-restore.md).
