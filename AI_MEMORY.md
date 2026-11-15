# AI Memory — c0m.me Mail / Webmail Clone

## Branch and workflow constraints
- Active implementation branch: `feature/m2-imap-smtp-webmail`.
- Do not modify `main`, merge the pull request, or change unrelated branches.
- The owner authorized direct commits to this feature branch for implementation.
- Keep changes scoped to the PHP MVC webmail application and its tests/docs.

## Architecture
- Database migrations: import `database/migrations/001_initial_schema.sql` followed by `002_mail_send_attempts.sql`; the latter is required for durable SMTP idempotency.
- PHP 8.2+ MVC application; PHP 8.3+ is the recommended production baseline as PHP 8.2 security support ends 2026-12-31. MariaDB/MySQL through PDO.
- IMAP and SMTP are adapters to the external mail provider; this project does not operate an MTA or IMAP server.
- Mailbox credentials are encrypted at rest and must only be decrypted after ownership and active-status checks.
- CI workflow: `.github/workflows/php.yml`; unit test runner: `php tests/run.php` (40 cases before durable-send-repository follow-up); protocol integration runner: `php tests/integration-mail.php` against local GreenMail.

## Current implementation notes
- IMAP listing now supports UID-based older/newer cursors scoped to UIDVALIDITY; the UI resets stale cursors when UIDVALIDITY changes. Legacy offset support remains in the adapter for compatibility.
- IMAP move uses `imap_mail_move`; do not call broad `imap_expunge` because it can expunge unrelated messages marked Deleted in the source folder.
- SMTP acceptance and saving the Sent copy are separate outcomes. Never report SMTP acceptance as failed solely because the Sent APPEND failed.
- Compose forms carry a cryptographically random one-time token persisted in `mail_send_attempts`, scoped to user and mailbox. An atomic conditional DB update claims `pending` as `sending` before SMTP I/O; only the claim winner may send. The DB record stores a SHA-256 fingerprint of recipients, body, threading metadata and attachment bytes, rejecting reuse of the token with changed content. SMTP acceptance is persisted as `accepted` before optional Sent APPEND; accepted retries return the recorded result. A `sending` row older than two minutes becomes `uncertain` and is never automatically retried. Records older than 30 days are purged when a new compose token is issued. This is durable across PHP sessions/workers, but it cannot provide true exactly-once delivery if a process crashes after the SMTP server accepts DATA and before the acceptance update; that case is deliberately blocked as uncertain to prevent automatic duplicates.
- Real CraneMail hostnames, TLS configuration, credentials, and live delivery remain environment-dependent and must be tested on the target server.
- Do not claim real-server integration is verified by unit tests or GitHub Actions alone.

## Recent hardening
- IMAP search excludes messages marked Deleted and now distinguishes an empty result set from an IMAP protocol failure by clearing and inspecting the ext-imap error queue. Message-summary lookup failures are surfaced instead of silently becoming an empty inbox.
- Inbound attachment transfer decoding accepts all defined IMAP encoding values, including `ENCOTHER` as opaque data, and rejects malformed base64 instead of silently returning an empty attachment.
- Inbound MIME attachment filenames support RFC 2231 single-value and segmented UTF-8 parameters, with legacy `filename`/`name` fallback.
- IMAP folder names reject control characters and IMAP mailbox URL delimiters.
- Missing message overview records are omitted rather than rendered as empty message rows.
- MIME body and attachment retrieval failures are surfaced instead of silently becoming empty content.
- SMTP TLS peer and hostname verification are explicit.
- Oversized PHP POST bodies are detected before CSRF validation masks the upload error.
- Sent-copy archival status is displayed independently from SMTP acceptance.
- Public upload limits are documented and supplied through `public/.user.ini` (host handler support must be confirmed).
- Webmail view and action errors are mapped to user-safe messages; detailed server exception text is not rendered.
- Attachment downloads verify the requested folder against the authenticated mailbox's discovered folders.
- The MIME header fallback emits folded RFC 2047 encoded-words when mbstring's encoder is unavailable.
- The IMAP APPEND cap accounts for base64 expansion of the 25 MB aggregate attachment limit; PHP upload memory guidance is 192 MB.
- Submission idempotency is session-scoped, not durable across session expiry or a lost session store; database-backed delivery idempotency remains a future hardening task. The pure `SubmissionState` classifier keeps pending/accepted/in-flight/uncertain transitions testable; CI covers its active and stale-send decisions.
- PHP sessions use strict mode, cookie-only transport, HttpOnly/SameSite cookies, 30-minute idle timeout and 12-hour absolute timeout.
- Global response headers include CSP, anti-framing, MIME sniffing protection, referrer policy, private no-store caching, and HSTS on HTTPS. Production PHP INI guidance disables displayed errors and enables server-side error logging. CSP-compatible UI behavior is delegated to the same-origin JavaScript asset; the public .user.ini is denied by Apache.

- GitHub Actions runs the PHP unit suite on PHP 8.2–8.5. The PHP 8.5 matrix leg runs IMAPS/SMTPS integration against GreenMail and SMTP STARTTLS against `tests/fixtures/starttls_smtp_server.py`, all with a trusted ephemeral TLS certificate. GreenMail does not implement STARTTLS (upstream issue #135), so do not try to enable it on its SMTP endpoint. These checks validate the adapter contract, not the production CraneMail endpoint.

- GreenMail integration revealed that ext-imap may expose MIME parameter collections as `stdClass` rather than arrays; `ImapClient` now normalizes array, Traversable, and object parameter groups before RFC 2231 decoding.

- Compose and server-side Drafts accept To, Cc, and Bcc recipients. SMTP envelope delivery includes all recipient classes; RFC 5322 headers emit To and Cc only, never Bcc. The MIME builder validates and deduplicates recipients across fields and enforces the aggregate 50-recipient limit. Unit tests cover Bcc privacy and invalid Cc recipient rejection.
