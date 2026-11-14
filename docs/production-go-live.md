# Production go-live checklist

This application is a webmail client. The mail provider remains responsible for mailbox provisioning, DNS, inbound delivery, SPF, DKIM, DMARC and the underlying MTA.

## 1. Hosting and secrets

- [ ] Use PHP 8.1+ with `imap`, `pdo_mysql`, `openssl`, `fileinfo` and `session` enabled.
- [ ] Use MariaDB 10.5+ or MySQL 8+.
- [ ] Keep `.env`, `app/`, `database/` and `bin/` outside the public document root.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, and an HTTPS `APP_URL`.
- [ ] Generate a unique 32-byte `APP_KEY`; back it up securely. Losing or rotating this key without re-encrypting mailbox credentials makes existing stored credentials unreadable.
- [ ] Keep `MAIL_ALLOW_INSECURE_AUTH=false`.
- [ ] Configure the provider's exact IMAP/SMTP hostnames, ports, TLS modes and timeouts.
- [ ] Set PHP `upload_max_filesize` and `post_max_size` above the configured attachment total limit.

## 2. Database and application

- [ ] Import `database/migrations/001_initial_schema.sql`.
- [ ] Import `database/migrations/002_login_rate_limits.sql`.
- [ ] Run `php bin/check-requirements.php` and resolve every failure.
- [ ] Create the first administrator with `php bin/create-admin.php admin@example.com`; enter the password at the hidden prompt.
- [ ] Confirm the application is served only over HTTPS and that the production session cookie is Secure/HttpOnly.

## 3. Provider and mailbox verification

- [ ] Create a real mailbox at the provider before registering it in c0m.me Mail.
- [ ] Register the mailbox using manual provisioning and activate it only after the provider confirms it exists.
- [ ] Use the administrator's Test connection action to verify IMAP folder discovery and SMTP TLS/AUTH without sending a message.\n- [ ] Open the mailbox and confirm Inbox message listing.
- [ ] Send a message to an external mailbox and verify receipt.
- [ ] Reply from the external mailbox and verify it appears in Inbox.
- [ ] Confirm the Sent copy appears in the configured Sent folder. Set `MAIL_FOLDER_SENT` if the provider uses a non-standard folder path.
- [ ] Save, reopen, edit and send a Draft; verify its attachments remain intact and the Draft disappears after sending.
- [ ] Verify BCC recipients receive the message but are not exposed in the stored sent headers.
- [ ] Test attachment upload/download and confirm the configured file and total size limits.
- [ ] If folder names are localized or unusual, set `MAIL_FOLDER_INBOX`, `MAIL_FOLDER_SENT`, `MAIL_FOLDER_DRAFTS`, `MAIL_FOLDER_SPAM` and `MAIL_FOLDER_TRASH` to the exact IMAP paths.

## 4. Operations

- [ ] Back up the database and `.env` securely; never commit secrets to Git.
- [ ] Protect the `APP_KEY` backup separately from ordinary database backups.
- [ ] Change mailbox passwords at the provider first, then update the encrypted copy through the admin credential-update form.
- [ ] If SMTP accepts a message but Sent-copy storage fails, heed the warning in the UI; do not resend unless a duplicate is intended.
- [ ] Keep the application and PHP runtime patched.

## Scope and verification limits

CI uses MariaDB and GreenMail to verify SQL persistence and real IMAP/SMTP protocol behavior. It does not prove compatibility with a particular CraneMail production account, the target cPanel host, DNS records or the provider's mailbox provisioning API. The CraneMail HTTP provisioning adapter remains provisional until its official API contract and credentials are available.