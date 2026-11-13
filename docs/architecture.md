# Architecture

## Layers

- `public/index.php`: front controller and route registration.
- `app/Core`: configuration, PDO connection, router, view, CSRF, and authentication primitives.
- `app/Controllers`: HTTP orchestration.
- `app/Repositories`: parameterized SQL persistence.
- `app/Services`: mailbox lifecycle and encryption boundary.
- `app/Contracts`: replaceable identity and mail-provider contracts.
- `app/Providers`: local session identity, manual provider, and CraneMail HTTP adapter.

## Identity adapter

Controllers obtain the current principal through `MemberProviderInterface`. The shipped `LocalSessionMemberProvider` reads the authenticated local account from the database. A future OIDC implementation should validate issuer, audience, signature, state, nonce, and PKCE, then map the verified subject to a local member. No Account Center endpoints or SSO assumptions are embedded.

## Mail-provider contract

`MailProviderInterface` exposes provision, suspend, and delete. Manual mode returns `manual_pending` and makes no external API call. Administrators must create the mailbox at CraneMail first, then register it in this application. Manual suspension is a locally recorded confirmation after the operator suspends it in CraneMail.

The CraneMail adapter sends JSON over HTTPS with bearer authentication. No verified CraneMail API specification or credentials were supplied during implementation, so the adapter uses configurable endpoint paths and a clearly documented provisional JSON contract:
- Provision: POST configured `CRANEMAIL_PROVISION_PATH`, JSON request includes `email`, `password`, `display_name`; response must include a non-empty `id`.
- Suspend: POST configured `CRANEMAIL_SUSPEND_PATH` with `{id}` replaced by the URL-encoded provider ID.
- Delete: DELETE configured `CRANEMAIL_DELETE_PATH` with the same placeholder replacement.

This contract is an integration boundary, not a claim that CraneMail's production API uses these routes or field names. Validate against CraneMail documentation/sandbox before switching from manual mode. SMTP/IMAP connection settings are configured separately and never inferred from API responses.

## Mailbox secrets

Mailbox passwords are encrypted with AES-256-GCM using the decoded 32-byte `APP_KEY`; only ciphertext, nonce, and tag are stored. The initial password is shown once to the administrator after creation for secure handoff. The application does not offer a later password reveal.

## Deliberate exclusions

No MTA/IMAP daemon, webmail message reader, outbound mail composer, password-reset email, billing, domain verification, or OIDC flow is included yet. These require verified provider capabilities and operational policies.
