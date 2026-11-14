# Architecture

## Application layers

- `public/index.php`: front controller, dependency wiring and routes.
- `app/Core`: configuration, PDO, router, authentication, CSRF and views.
- `app/Controllers`: request validation, authorization and HTTP responses, including administrator member lifecycle management.
- `app/Repositories`: parameterized MariaDB/MySQL persistence.
- `app/Services`: mailbox credential decryption, transport factory, MIME builder, webmail orchestration and privacy-preserving login throttling.
- `app/Contracts`: identity, IMAP and SMTP interfaces.
- `app/Providers`: local identity, manual/CraneMail provisioning, native PHP IMAP and socket SMTP.

## Authentication abuse controls

Failed login attempts are stored as HMAC-SHA256 hashes of normalized email and remote IP, keyed by `APP_KEY`; raw identifiers are not persisted. The throttle blocks after 10 failures for an email/IP pair or 30 failures for one email across IPs within a rolling 15-minute window. It deliberately avoids a global IP lockout so shared reverse-proxy addresses cannot lock out unrelated users. A successful login clears that email's prior failures. Old rows are pruned opportunistically after 30 days. Apply migration `002_login_rate_limits.sql` after the initial schema.

## Identity provider boundary

Controllers use `MemberProviderInterface`. The current local session provider is used for development. A future OIDC provider must validate issuer, audience, signature, state, nonce and PKCE, then map the verified subject to a local member. No Account Center endpoint or SSO assumption is embedded.

## Mail infrastructure and transport

CraneMail is an external mailbox host, not a webmail UI dependency. `ImapClientInterface` and `SmtpClientInterface` isolate the engine from any specific mail host. `NativeImapClient` uses PHP's IMAP extension with TLS certificate verification and configurable open/read/write timeouts. `SocketSmtpClient` implements SMTP greeting/EHLO, optional STARTTLS or implicit TLS, AUTH LOGIN, envelope recipients, DATA, MIME transfer and dot-stuffing.

The configured mailbox email/password authenticate to both protocols. The password is AES-256-GCM encrypted in the mailboxes table and decrypted only in request memory. It is not stored in a PHP session or written to application logs. During manual provisioning, the initial password is shown only in the immediate no-store admin response, not in a session flash. SMTP AUTH commands and IMAP credentials are not logged.

The IMAP adapter lists real server folders, maps common folder names to Inbox/Sent/Drafts/Spam/Trash roles, paginates by UID, searches message text, fetches MIME content and attachments, updates Seen/Flagged flags, moves/deletes messages, creates custom folders and APPENDs drafts/Sent copies. Folder role matching is name-based at present; providers with non-English or unusual names may need explicit folder mapping.

## Message composition and attachments

`MimeMessageBuilder` produces multipart MIME. To, CC and BCC are used as SMTP envelope recipients; BCC is omitted from sent-message headers. Saved drafts store BCC in a private `X-C0m-Draft-Bcc` header so the field survives reopening; that header is emitted only for drafts and never for sent messages. Drafts can be reopened, edited and sent; existing attachments are downloaded from IMAP and retained when the draft is replaced or sent. Attachment uploads are capped by configuration, limited to five files, and executable/script/web-page extensions are rejected. Downloads are forced to `application/octet-stream` with `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.

HTML message bodies are processed through an allowlist sanitizer and displayed in a sandboxed iframe without scripts, event handlers, remote images or embedded frames. The front controller also emits a restrictive Content Security Policy, frame denial, MIME sniffing protection, a no-referrer policy and a restrictive Permissions Policy. This is intentionally a constrained email preview, not a full fidelity browser renderer.

## Synchronization model

The current milestone uses on-demand live IMAP reads and UID-based pagination/search; it does not yet implement IMAP IDLE, background polling, push notifications, or a local message cache. Administrators can test an active mailbox's IMAP folder listing and SMTP TLS/AUTH handshake without sending mail. SMTP acceptance is reported separately from the best-effort Sent-folder APPEND: a Sent-copy failure is logged without secrets and shown to the user as a warning; the UI explicitly advises against resending a message already accepted by SMTP. Draft-send cleanup failures are also surfaced so users do not accidentally send duplicates.

## CraneMail provisioning boundary

Manual provisioning remains the default. The existing CraneMail HTTP provisioning adapter has configurable endpoints but is provisional and must be checked against official CraneMail API documentation before enabling. Message send/read operations do not call or infer any CraneMail-specific HTTP API; they use the standard protocols above.

## Production exclusions

No MTA/IMAP server is run by this application. OIDC, self-service password reset, billing, domain verification, inline CID images, OAuth2 mail authentication, provider-special-use folder discovery and push/IDLE sync are not part of this milestone.
