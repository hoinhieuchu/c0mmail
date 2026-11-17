# Production go-live checklist

This application is a webmail client and mailbox-management layer. It connects to mailboxes hosted by an external provider over IMAP and SMTP. The provider remains responsible for mailbox provisioning, inbound delivery, DNS, SPF, DKIM, DMARC and the underlying MTA. c0m.me Mail does not operate an SMTP/IMAP server.

Use this checklist for a fresh deployment. For the detailed cPanel walkthrough, see [installation-cpanel.md](installation-cpanel.md).

## 1. Hosting, runtime and document root

- [ ] Use PHP 8.2 or newer; PHP 8.3+ is recommended.
- [ ] Enable `pdo_mysql`, `imap`, `openssl`, `dom` / XML, `fileinfo`, `json` and `session`.
- [ ] Use MariaDB 10.5+ or MySQL 8+.
- [ ] Serve only the repository's `public/` directory as the web document root.
- [ ] Keep `.env`, `app/`, `bin/`, `database/`, `docs/` and `tests/` outside the public document root.
- [ ] Enable HTTPS before creating accounts or entering mailbox credentials.
- [ ] Set PHP `display_errors=Off`, `log_errors=On` and `expose_php=Off`.
- [ ] Configure upload limits to support the application's attachment policy: `upload_max_filesize=25M`, `post_max_size=27M`, `max_file_uploads=10`, and `memory_limit=192M`. Confirm the active PHP handler actually reads `public/.user.ini`; otherwise use the hosting control panel.

## 2. Secrets and environment

- [ ] Copy `.env.example` to `.env` outside the document root.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false` and the exact HTTPS `APP_URL`.
- [ ] Generate a unique application key with:

  ```sh
  php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
  ```

- [ ] Store the complete generated value in `APP_KEY`. It must decode to exactly 32 bytes.
- [ ] Back up `APP_KEY` securely and separately from ordinary database backups. Losing the matching key makes encrypted mailbox credentials unreadable; do not rotate it without a planned re-encryption procedure.
- [ ] Set database host, port, database name, username and password. Grant the database user access only to this application's database.
- [ ] Set `MAIL_DOMAIN` to the domain used by provisioned mailboxes.
- [ ] Configure exact IMAP/SMTP hostnames, ports and encryption modes from the mail provider's official instructions. Use implicit TLS (`ssl`) or STARTTLS only as specified by that provider.
- [ ] Keep TLS certificate and hostname verification enabled. Do not work around certificate errors by disabling verification.
- [ ] Keep `MAIL_PROVIDER=manual` unless a separately verified provider provisioning adapter is available. CraneMail automated provisioning is intentionally unavailable until its official API contract and credentials have been verified.
- [ ] Leave `APP_TRUSTED_PROXY_CIDRS` empty unless the application is behind a known reverse proxy. If required, configure only the proxy's exact trusted CIDRs; do not trust arbitrary forwarded headers.

## 3. Preflight and database installation

Run commands from the application root using the same PHP version selected for the website:

```sh
php bin/check-environment.php
php bin/migrate.php
php bin/check-environment.php
```

- [ ] Review the first preflight. On a fresh database, missing-table warnings are expected before migrations; resolve every failure.
- [ ] Run `php bin/migrate.php`. The migration runner applies the numbered SQL migrations in filename order and records completed migrations in `schema_migrations`. Do not manually import only selected migration files.
- [ ] Review the second preflight and resolve every failure. Confirm the database connection and required tables are reported as available.
- [ ] For an existing database created before migration tracking, take and verify a full backup first. Confirm the schema is complete, including all required tables/columns and the send-attempt `failed` state, before considering `php bin/migrate.php --baseline-existing`. Never use the baseline option on a fresh or partially migrated database.
- [ ] Create the first administrator using `php bin/create-admin.php admin@example.com`; enter the password at the hidden terminal prompt. Use a unique strong password (at least 14 characters is recommended).
- [ ] Create member accounts with `php bin/create-member.php member@example.com` when needed. Self-service registration and password reset are not currently included.

## 4. Mailbox provisioning and live protocol checks

- [ ] Create each real mailbox at the external mail provider first.
- [ ] Register the same mailbox address and its provider password in the c0m.me admin panel. Mailbox passwords are encrypted at rest by the application.
- [ ] Keep the mailbox pending until provider-side creation is confirmed; activate only after successful connection checks.
- [ ] Run `php bin/check-mailbox.php MAILBOX_ID`. It checks the saved credentials against IMAP, discovers folders, authenticates to SMTP over verified TLS and issues SMTP NOOP. It does not send a message.
- [ ] Sign in as the mailbox owner and open `/mail`.
- [ ] Send a test message to an address you control and verify external receipt.
- [ ] Reply from the external mailbox and verify the reply appears in Inbox.
- [ ] Verify Sent copy, Drafts, read/unread, star, search, pagination, move-to-trash/spam and attachment upload/download.
- [ ] Verify BCC recipients receive the message and are not exposed in message headers.
- [ ] If the provider uses non-standard folder names, configure the corresponding `MAIL_FOLDER_*` settings using exact IMAP folder paths.
- [ ] Confirm the hosting provider permits outbound TCP connections to the configured IMAP and SMTP endpoints.

## 5. Security and operations

- [ ] Restrict filesystem permissions on `.env` and application files.
- [ ] Restrict administrator access and use unique passwords.
- [ ] Back up the database and `.env` securely; protect the application key separately.
- [ ] Perform a restore test before onboarding real users.
- [ ] Change mailbox passwords at the provider first, then update the encrypted credential in the admin panel.
- [ ] Keep PHP, the operating system, the hosting control panel and the application patched.
- [ ] Review server-side logs for operational errors; never enable public error display in production.
- [ ] If SMTP accepts a message but saving its Sent copy fails, heed the UI warning. Do not resend unless a duplicate is intended.
- [ ] Remember that an SMTP result lost after DATA can be inherently ambiguous. The application marks such attempts uncertain and blocks automatic retry to reduce duplicate delivery risk.

## 6. Verification boundaries and features not yet included

GitHub Actions validates PHP syntax and unit behavior, MariaDB persistence, and IMAP/SMTP protocol integration against local test services. These checks do not prove compatibility with a particular production mailbox, the target cPanel host, DNS configuration or a provider's provisioning API. Complete the live checks above before accepting real users.

The current application does not include member self-service registration, password reset, mailbox password reset, OIDC, billing or verified CraneMail automated provisioning. Webmail refresh is periodic polling, not IMAP IDLE/push.