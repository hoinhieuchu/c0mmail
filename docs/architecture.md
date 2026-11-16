# Architecture

## Layers

- `public/index.php`: front controller and route registration.
- `app/Core`: configuration, PDO connection, router, view, CSRF, and authentication primitives.
- `app/Controllers`: HTTP orchestration.
- `app/Repositories`: parameterized SQL persistence.
- `app/Services`: mailbox lifecycle, encrypted credential access, IMAP client, and SMTP transport.
- `app/Contracts`: replaceable identity and mail-provider contracts.
- `app/Providers`: local session identity, manual provider, and fail-closed CraneMail adapter.

## Authentication and proxy trust

Login failures are throttled by HMAC keys for normalized account and client IP. `ClientIpResolver` trusts `REMOTE_ADDR` by default; it only accepts `CF-Connecting-IP` or `X-Real-IP` when the immediate peer matches an explicitly configured `APP_TRUSTED_PROXY_CIDRS` entry. `X-Forwarded-For` is not used. Configure only the actual reverse-proxy CIDRs.

## Identity adapter

Controllers obtain the current principal through `MemberProviderInterface`. The shipped `LocalSessionMemberProvider` reads the authenticated local account from the database. A future OIDC implementation should validate issuer, audience, signature, state, nonce, and PKCE, then map the verified subject to a local member. No Account Center endpoints or SSO assumptions are embedded.

## Mail-provider contract

`MailProviderInterface` exposes `provision`, `suspend`, `delete`, and `connectionSettings`. The connection settings method returns SMTP/IMAP host, port, and encryption mode without exposing passwords.

Manual mode returns `manual_pending` and makes no external API call. An administrator must create the mailbox at CraneMail, then confirm activation in the admin screen. Manual suspension is recorded only after the operator confirms that they already suspended the mailbox in CraneMail. The app does not claim to have changed the remote mailbox in manual mode.

The shipped `CraneMailProvider` is deliberately fail-closed for provisioning, suspension and deletion. CraneMail's official API contract and credentials have not yet been verified, so the application must not invent endpoint paths or claim that remote mailbox lifecycle actions succeeded. Use `MAIL_PROVIDER=manual`: provision the mailbox in the real provider control panel, register its credentials in c0m.me, and confirm activation only after provider-side creation. SMTP/IMAP connection settings are read from the explicit environment variables; they are never inferred from an unverified API response.

## Mailbox secrets

Mailbox passwords are encrypted with AES-256-GCM using the decoded 32-byte `APP_KEY`; only ciphertext, nonce, and tag are stored. The initial password is shown once to the administrator after creation for secure handoff. The application does not offer a later password reveal.

## Webmail transport

- Webmail performs a lightweight authenticated IMAP polling check every 90 seconds while visible, scoped to the selected folder's UIDVALIDITY and latest UID. The endpoint is session-throttled and never forces a page reload, preserving unsent compose content. This is not a persistent IMAP IDLE connection.
- `ImapClient` uses PHP's IMAP extension and the configured IMAP host, port, and encryption mode. It discovers server folders, lists messages by UID with server-side TEXT search and UID-cursor pagination scoped to UIDVALIDITY, reads text/plain (or text/html fallback) bodies, marks opened messages as seen, supports explicit read/unread and flagged/star states, appends Sent/Drafts messages, and moves messages by UID.
- Rich-text compose uses a contenteditable editor with basic formatting controls. Every outbound HTML body is sanitized in `MimeMessageBuilder` using a DOM allow-list: scripts, styles, event handlers, embedded media, and unsafe URL schemes are removed. Only basic semantic formatting and HTTP(S)/mailto links are retained; if DOM/XML is unavailable, HTML degrades safely to escaped text.
- `SmtpClient` uses authenticated SMTP over implicit TLS or STARTTLS. `MimeMessageBuilder` constructs RFC 5322 multipart messages with UTF-8 plain text, optional HTML alternatives, base64 attachments, and validated In-Reply-To/References headers. The exact generated message is reused for SMTP delivery and the Sent IMAP copy; Drafts use the same builder.
- Mailbox credentials are decrypted only after the authenticated user is verified as the mailbox owner and the mailbox is active. Credentials are never returned to templates or browser JavaScript.
- IMAP certificate verification remains enabled. Configure valid certificates on the mail server; do not disable peer verification to work around certificate errors.
- Folder names are discovered from the IMAP server. Draft and Sent operations are enabled only when matching server folders exist. Trash/Spam moves require those folders to exist.

## Deployment and diagnostics

- `bin/check-environment.php` validates PHP extensions, production URL/key configuration, database connectivity and required schema tables.
- `bin/migrate.php` applies ordered SQL migrations and records them in `schema_migrations`; `--baseline-existing` is only for an already migrated database after a backup and schema verification.
- `bin/check-mailbox.php ID` authenticates the stored mailbox to IMAP, lists folders, then authenticates to SMTP over verified TLS and issues NOOP without sending a message.

## Deliberate exclusions

The application does not run an MTA/IMAP daemon. Current webmail supports plain-text/HTML composition and inbound/outbound MIME attachments; server-side full-text indexing beyond IMAP TEXT search, rich-text editing, full-folder thread aggregation (current-page RFC conversation grouping is implemented), push/IDLE sync, password-reset email, billing, domain verification, and OIDC remain future work. CraneMail's actual IMAP/SMTP endpoints and authentication policies must be verified in the target environment before production use.
