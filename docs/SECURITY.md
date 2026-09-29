# Security

## Credential storage

QA and production tokens are never stored in the general settings option. Each token is encrypted with AES-256-GCM using a fresh 96-bit nonce and an authentication tag. The encryption key is derived at runtime with HKDF-SHA-256 from WordPress `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, and `NONCE_KEY`; the derived key is never persisted. Stored values carry a version prefix for future rotation/migration.

The settings form always renders token inputs empty. A blank submission retains the existing ciphertext; a nonblank value replaces it. The UI reveals only whether a token is configured. Missing salts, unavailable OpenSSL, malformed ciphertext, or authentication failure cause a closed failure: plaintext is not stored and decryption returns no usable token to callers.

Changing WordPress salts invalidates existing ciphertext by design. After rotating salts, enter replacement tokens in the administrator screen.

## Administrative controls

Settings require the `manage_woocommerce` capability and a WordPress nonce. Inputs are allowlisted or sanitized, and all rendered values are escaped. Integration services do not initialize unless WooCommerce is active.

Dry-run start, resume, cancellation, CSV export, and expired-record purge each require `manage_woocommerce` and an action-specific nonce. Cancellation nonces are bound to the displayed run ID and immutable claim generation, so a stale form cannot cancel a resumed claim. Cryptographic claim and intent tokens are stored server-side and passed only to background jobs; they are never rendered in administrator HTML or logs. Remote runs require an enabled QA configuration and a recorded successful QA connection test. Production connection tests never write `ideaxperts_ea_qa_connection_status`. Production is rejected by the catalog service even if Production is otherwise configured. Reconciliation performs no Endless Aisles network request.

Import preparation, confirmation, and cancellation also require `manage_woocommerce` and action-specific nonces. Approval manifests are derived exclusively on the server from completed dry-run rows and persisted manual-resolution targets, canonically hashed, and retained only behind a short-lived random transient key. The browser cannot select an arbitrary action or change eligibility. Manifest and item records bind source scope, environment, dry-run generation, settings/policy versions, and freshness hashes; audit events contain bounded identifiers and state transitions, never credentials, tokens, or raw vendor payloads.

## Logging

Structured context is recursively redacted by key and inline secret patterns before insertion. Authentication headers are never passed to the logger. `X-EA-REQUEST-TOKEN`, authorization values, API keys, tokens, cookies, sessions, passwords, secrets, payment fields, and customer contact/address fields are redacted; unsupported objects and resources are rejected from context. API response bodies are not logged. The table retains at most 2,000 recent entries; administrators can view the latest 50.

## Network safety

Documented base URLs and endpoint paths are committed; credentials are not. The API uses `X-EA-REQUEST-TOKEN` and never Bearer authentication. The connection test sends one explicit, read-only product-list GET, stores no returned product data, and logs neither token nor URL. The API client uses WordPress safe HTTP requests, disables redirects, enforces bounded timeouts, and applies a 5 MiB default response ceiling before JSON decoding. Structured failures exclude response bodies, transport details, URLs, and credentials. QA is the default. Production resolution and request orchestration both require an exact checkbox confirmation, stored as a versioned internal sentinel, in addition to selecting Production.

The release builder copies only an explicit source allowlist into an isolated build directory. It does not read or copy `.env` files, WordPress configuration, Composer authentication files, or environment credentials.

## Catalog and CSV safety

The store scanner calls read-only WooCommerce getters and records only product identity, status/type, administrator display title, configured identifier fields, parent identity, and existing mappings. It never enumerates unrelated metadata. Metadata-key settings are syntax allowlisted and never interpolated into SQL.

Dry-run persistence excludes credentials and raw API bodies. Results include only catalog comparison fields. CSV is streamed directly to the administrator without a permanent file; leading whitespace and control characters are stripped, then cells beginning with `=`, `+`, `-`, or `@` receive a leading apostrophe to prevent formula execution.

Dry-run workflow state and its scheduling outbox use verified InnoDB tables. Action Scheduler storage is replaceable and is treated as an external system: committed intents are dispatched after the plugin transaction, ambiguous dispatches are reconciled by exact arguments, and cancellation is not terminal until matching actions have been verified absent.

Import workflow state follows the same plugin-owned outbox boundary. Production UPC reservations use a single `woocommerce_catalog` namespace across vendor sources and environments so two imports cannot claim the same identifier; those reservations are only coordination records and never stand in for the live WooCommerce catalog. QA uses an isolated `preview:qa` namespace and cannot establish Production ownership. Vendor catalog identity ownership remains source- and environment-scoped. Before `ready` and again inside the final-permit transaction, a read-only provider directly loads the expected target and streams bounded pages while retaining only matching UPC owners, then re-reads scoped mappings. The accepted evidence is a short-lived HMAC bound to item, run, source, environment, approval generation, live-state hash, and timestamp. The final write permit also locks and verifies the current durable action, logical key, dispatch generation, execution token, and unexpired execution lease. It rejects changed live state, stale callbacks, transplanted/tampered evidence, expired ownership, or cancellation before entering `applying`; `applying` work then requires explicit reconciliation rather than lease-based replay.

## Reporting issues

Report suspected credential exposure privately to the project owner. Do not include tokens, authorization headers, customer payment data, or production payloads in issue reports.
