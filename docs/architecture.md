# Architecture

## Layers

- `public/index.php`: front controller and route registration.
- `app/Core`: configuration, PDO connection, router, view, CSRF, and authentication primitives.
- `app/Controllers`: HTTP orchestration.
- `app/Repositories`: parameterized SQL persistence.
- `app/Services`: mailbox lifecycle, encrypted credential access, IMAP client, and SMTP transport.
- `app/Contracts`: replaceable identity and mail-provider contracts.
- `app/Providers`: local session identity, manual provider, and CraneMail HTTP adapter.

## Identity adapter

Controllers obtain the current principal through `MemberProviderInterface`. The shipped `LocalSessionMemberProvider` reads the authenticated local account from the database. A future OIDC implementation should validate issuer, audience, signature, state, nonce, and PKCE, then map the verified subject to a local member. No Account Center endpoints or SSO assumptions are embedded.

## Mail-provider contract

`MailProviderInterface` exposes `provision`, `suspend`, `delete`, and `connectionSettings`. The connection settings method returns SMTP/IMAP host, port, and encryption mode without exposing passwords.

Manual mode returns `manual_pending` and makes no external API call. An administrator must create the mailbox at CraneMail, then confirm activation in the admin screen. Manual suspension is recorded only after the operator confirms that they already suspended the mailbox in CraneMail. The app does not claim to have changed the remote mailbox in manual mode.

The CraneMail adapter sends JSON over HTTPS with bearer authentication. No verified CraneMail API specification or credentials were supplied during implementation, so the adapter uses configurable endpoint paths and a clearly documented provisional JSON contract:
- Provision: POST configured `CRANEMAIL_PROVISION_PATH`, JSON request includes `email`, `password`, `display_name`; response must include a non-empty `id`.
- Suspend: POST configured `CRANEMAIL_SUSPEND_PATH` with `{id}` replaced by the URL-encoded provider ID.
- Delete: DELETE configured `CRANEMAIL_DELETE_PATH` with the same placeholder replacement.

This contract is an integration boundary, not a claim that CraneMail's production API uses these routes or field names. Validate against CraneMail documentation/sandbox before switching from manual mode. SMTP/IMAP connection settings are read from environment configuration; they are not inferred from unverified API responses.

## Mailbox secrets

Mailbox passwords are encrypted with AES-256-GCM using the decoded 32-byte `APP_KEY`; only ciphertext, nonce, and tag are stored. The initial password is shown once to the administrator after creation for secure handoff. The application does not offer a later password reveal.

## Webmail transport

- `ImapClient` uses PHP's IMAP extension and the configured IMAP host, port, and encryption mode. It discovers server folders, lists the latest messages by UID, reads text/plain (or text/html fallback) bodies, marks opened messages as seen, appends Sent/Drafts messages, and moves messages by UID.
- `SmtpClient` uses authenticated SMTP over implicit TLS or STARTTLS, validates recipients and header values, and sends UTF-8 plain-text messages. The webmail controller appends a copy to the server's Sent folder when one is discoverable.
- Mailbox credentials are decrypted only after the authenticated user is verified as the mailbox owner and the mailbox is active. Credentials are never returned to templates or browser JavaScript.
- IMAP certificate verification remains enabled. Configure valid certificates on the mail server; do not disable peer verification to work around certificate errors.
- Folder names are discovered from the IMAP server. Draft and Sent operations are enabled only when matching server folders exist. Trash/Spam moves require those folders to exist.

## Deliberate exclusions

The application does not run an MTA/IMAP daemon. Current webmail is plain-text compose and text-body reading; MIME attachments, rich HTML composition, full-text search, robust pagination, conversation threading, push/IDLE sync, password-reset email, billing, domain verification, and OIDC remain future work. CraneMail's actual IMAP/SMTP endpoints and authentication policies must be verified in the target environment before production use.
