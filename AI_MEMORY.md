# AI Memory — c0m.me Mail / Webmail Clone

## Branch and workflow constraints
- Active implementation branch: `feature/m2-imap-smtp-webmail`.
- Do not modify `main`, merge the pull request, or change unrelated branches.
- The owner authorized direct commits to this feature branch for implementation.
- Keep changes scoped to the PHP MVC webmail application and its tests/docs.

## Architecture
- PHP 8.1+ MVC application; MariaDB/MySQL through PDO.
- IMAP and SMTP are adapters to the external mail provider; this project does not operate an MTA or IMAP server.
- Mailbox credentials are encrypted at rest and must only be decrypted after ownership and active-status checks.
- CI workflow: `.github/workflows/php.yml`; test runner: `php tests/run.php`.

## Current implementation notes
- IMAP listing uses UID search and offset-based pagination; page stability under new arrivals still needs UID-cursor pagination and UIDVALIDITY handling.
- IMAP move uses `imap_mail_move`; do not call broad `imap_expunge` because it can expunge unrelated messages marked Deleted in the source folder.
- SMTP acceptance and saving the Sent copy are separate outcomes. Never report SMTP acceptance as failed solely because the Sent APPEND failed.
- Real CraneMail hostnames, TLS configuration, credentials, and live delivery remain environment-dependent and must be tested on the target server.
- Do not claim real-server integration is verified by unit tests or GitHub Actions alone.

## Recent hardening
- IMAP search excludes messages marked Deleted.
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
