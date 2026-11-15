# AI Memory — c0m.me Mail / Webmail Clone

## Branch and workflow constraints
- Active implementation branch: `feature/m2-imap-smtp-webmail`.
- Do not modify `main`, merge the pull request, or change unrelated branches.
- The owner authorized direct commits to this feature branch for implementation.
- Keep changes scoped to the PHP MVC webmail application and its tests/docs.

## Architecture
- Database migrations: import `database/migrations/001_initial_schema.sql` followed by `002_mail_send_attempts.sql`, `003_send_attempt_failed_state.sql`, and `004_login_rate_limits.sql`; these provide durable SMTP idempotency, safe failure retries, and persistent login throttling.
- PHP 8.2+ MVC application; PHP 8.3+ is the recommended production baseline as PHP 8.2 security support ends 2026-12-31. MariaDB/MySQL through PDO.
- IMAP and SMTP are adapters to the external mail provider; this project does not operate an MTA or IMAP server.
- Mailbox credentials are encrypted at rest and must only be decrypted after ownership and active-status checks.
- CI workflow: `.github/workflows/php.yml`; unit test runner: `php tests/run.php` (51 cases); MariaDB persistence integration runner: `php tests/database-send-attempts.php`; protocol integration runner: `php tests/integration-mail.php` against local GreenMail.

## Current implementation notes
- IMAP listing now supports UID-based older/newer cursors scoped to UIDVALIDITY; the UI resets stale cursors when UIDVALIDITY changes. Legacy offset support remains in the adapter for compatibility.
- IMAP move uses `imap_mail_move`; do not call broad `imap_expunge` because it can expunge unrelated messages marked Deleted in the source folder.
- SMTP acceptance and saving the Sent copy are separate outcomes. Never report SMTP acceptance as failed solely because the Sent APPEND failed.
- Compose forms carry a cryptographically random one-time token persisted in `mail_send_attempts`, scoped to user and mailbox. An atomic conditional DB update claims `pending` as `sending` before SMTP I/O; only the claim winner may send. The DB record stores a SHA-256 fingerprint of recipients, body, threading metadata and attachment bytes, rejecting reuse of the token with changed content. SMTP acceptance is persisted as `accepted` before optional Sent APPEND; accepted retries return the recorded result. A `sending` row older than fifteen minutes becomes `uncertain` and is never automatically retried. Records older than 30 days are purged when a new compose token is issued. This is durable across PHP sessions/workers, but it cannot provide true exactly-once delivery if a process crashes after the SMTP server accepts DATA and before the acceptance update; that case is deliberately blocked as uncertain to prevent automatic duplicates.
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
- SMTP adapter wraps delivery errors with a phase-aware `SmtpDeliveryException`: failures before DATA are definitive and persisted as `failed`, permitting an atomic retry with the same token; failures after DATA submission are `uncertain` and never automatically retried. Migration `003_send_attempt_failed_state.sql` extends the status enum for existing databases.
- Durable send idempotency is stored in MySQL by `SendAttemptRepository`: compose tokens are scoped to user/mailbox, claimed atomically before SMTP I/O, and fingerprint recipients, content, threading metadata, and attachment bytes. Only the claim winner sends; accepted retries return the recorded state; stale `sending` rows older than fifteen minutes become `uncertain` and are never automatically retried. Unused `pending` tokens older than one day and all records older than 30 days are purged when a new compose token is issued. A crash after remote SMTP acceptance but before persisting acceptance remains inherently ambiguous and is blocked rather than retried. The pure `SubmissionState` classifier remains for unit-tested state semantics.
- PHP sessions use strict mode, cookie-only transport, HttpOnly/SameSite cookies, 30-minute idle timeout and 12-hour absolute timeout.
- Global response headers include CSP, anti-framing, MIME sniffing protection, referrer policy, private no-store caching, and HSTS on HTTPS. Production PHP INI guidance disables displayed errors and enables server-side error logging. CSP-compatible UI behavior is delegated to the same-origin JavaScript asset; the public .user.ini is denied by Apache.

- GitHub Actions runs the PHP unit suite on PHP 8.2–8.5 and a dedicated MariaDB-backed persistence suite. The PHP 8.5 matrix leg runs IMAPS/SMTPS integration against GreenMail and SMTP STARTTLS against `tests/fixtures/starttls_smtp_server.py`, all with a trusted ephemeral TLS certificate. GreenMail does not implement STARTTLS (upstream issue #135), so do not try to enable it on its SMTP endpoint. These checks validate the adapter contract, not the production CraneMail endpoint.

- GreenMail integration revealed that ext-imap may expose MIME parameter collections as `stdClass` rather than arrays; `ImapClient` now normalizes array, Traversable, and object parameter groups before RFC 2231 decoding.

- Compose and server-side Drafts accept To, Cc, and Bcc recipients. SMTP envelope delivery includes all recipient classes; RFC 5322 headers emit To and Cc only, never Bcc. The MIME builder validates and deduplicates recipients across fields and enforces the aggregate 50-recipient limit. Unit tests cover Bcc privacy and invalid Cc recipient rejection.

- SMTP response classification distinguishes explicit negative SMTP replies (4xx/5xx) from missing or malformed final responses. An explicit negative reply after DATA is a definitive rejection and may safely transition the same immutable send attempt to `failed`; a disconnect/timeout or unexpected non-negative response after DATA remains `uncertain`. Unit tests exercise both protocol response classes through socket-pair fixtures.

- Login authentication uses MariaDB-backed throttling: 10 failures per normalized account and 30 failures per client IP in a rolling 15-minute window trigger a 15-minute block. Keys are HMAC-SHA256 values derived from APP_KEY; raw email and IP values are not persisted. Successful authentication clears only that account counter; the shared IP counter remains so a valid account cannot erase evidence of password spraying. Unknown accounts use a fixed dummy password hash and blocked logins return the same generic response to reduce timing-based and response-based account enumeration.

- Conversation rows are grouped by RFC Message-ID, References, and In-Reply-To chains via `ConversationGrouper`; subject-only matching is intentionally avoided. Grouping is limited to the current 50-message cursor page, and the UI labels the count accordingly. Thread unread state is true only when every message on the page is seen; flagged state is aggregated across the page. References parsing is bounded to 8 KiB.

- Webmail sync uses an authenticated GET endpoint at `/mail/updates`, polled every 90 seconds only while the browser page is visible. It checks the selected folder's UIDVALIDITY and UIDs newer than the latest displayed message, is throttled to one check per session every 30 seconds, and only presents a manual refresh link. It must not auto-reload because compose fields may contain unsaved content. This is polling, not IMAP IDLE/push.

- Rich-text composition uses a contenteditable editor with basic bold/italic/underline/list/link controls. The submitted HTML is always sanitized in `MimeMessageBuilder` using a DOM allow-list: no scripts, styles, event attributes, embedded media, or unsafe URL schemes; only basic semantic formatting and HTTP(S)/mailto links survive. If DOM/XML is unavailable, the sanitizer safely degrades to escaped plain text. Tests cover sanitizer behavior and MIME output.
