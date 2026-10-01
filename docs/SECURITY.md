# Security

## Credential storage

QA and production tokens are never stored in the general settings option. Each token is encrypted with AES-256-GCM using a fresh 96-bit nonce and an authentication tag. The encryption key is derived at runtime with HKDF-SHA-256 from WordPress `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, and `NONCE_KEY`; the derived key is never persisted. Stored values carry a version prefix for future rotation/migration.

The settings form always renders token inputs empty. A blank submission retains the existing ciphertext; a nonblank value replaces it. The UI reveals only whether a token is configured. Missing salts, unavailable OpenSSL, malformed ciphertext, or authentication failure cause a closed failure: plaintext is not stored and decryption returns no usable token to callers.

Changing WordPress salts invalidates existing ciphertext by design. After rotating salts, enter replacement tokens in the administrator screen.

## Administrative controls

Settings require the `manage_woocommerce` capability and a WordPress nonce. Inputs are allowlisted or sanitized, and all rendered values are escaped. Integration services do not initialize unless WooCommerce is active.

Dry-run start, resume, cancellation, CSV export, and expired-record purge each require `manage_woocommerce` and an action-specific nonce. Cancellation nonces are bound to the displayed run ID and immutable claim generation, so a stale form cannot cancel a resumed claim. Cryptographic claim and intent tokens are stored server-side and passed only to background jobs; they are never rendered in administrator HTML or logs. Remote runs require an enabled QA configuration and a recorded successful QA connection test. Production connection tests never write `ideaxperts_ea_qa_connection_status`. Production is rejected by the catalog service even if Production is otherwise configured. Reconciliation performs no Endless Aisles network request.

Import preparation, confirmation, and cancellation also require `manage_woocommerce` and action-specific nonces. Approval manifests are derived exclusively on the server from completed dry-run rows and persisted manual-resolution targets, canonically hashed, and retained only behind a short-lived random transient key. The browser cannot select an arbitrary action or change eligibility. Manifest and item records bind source scope, environment, dry-run generation, settings/policy versions, and freshness hashes; audit events contain bounded identifiers and state transitions, never credentials, tokens, or raw vendor payloads.

Every import event uses a deterministic allowlisted serializer that checks encoded bytes against 4,000. Oversized values retain canonical SHA-256 evidence and byte counts; full EA/target/operation identities remain in event columns and immutable item/manifest state. Required audit insertion remains in the settlement transaction. QA reservation release requires exact QA source/environment plus preview ownership/namespace and a safely settled item. Cancellation retains reservations until authoritative settlement; ambiguous recovery retains them. Released rows cannot satisfy a final permit. Reuse atomically replaces the current item/token while historical events keep the prior operation reference. Production rows cannot match release predicates.

## Logging

Structured context is recursively redacted by key and inline secret patterns before insertion. Authentication headers are never passed to the logger. `X-EA-REQUEST-TOKEN`, authorization values, API keys, tokens, cookies, sessions, passwords, secrets, payment fields, and customer contact/address fields are redacted; unsupported objects and resources are rejected from context. API response bodies are not logged. The table retains at most 2,000 recent entries; administrators can view the latest 50.

## Network safety

Documented base URLs and endpoint paths are committed; credentials are not. The API uses `X-EA-REQUEST-TOKEN` and never Bearer authentication. The connection test sends one explicit, read-only product-list GET, stores no returned product data, and logs neither token nor URL. The API client uses WordPress safe HTTP requests, disables redirects, enforces bounded timeouts, and applies a 5 MiB default response ceiling before JSON decoding. Structured failures exclude response bodies, transport details, URLs, and credentials. QA is the default. Production resolution and request orchestration both require an exact checkbox confirmation, stored as a versioned internal sentinel, in addition to selecting Production.

The release builder copies only an explicit source allowlist into an isolated build directory. It does not read or copy `.env` files, WordPress configuration, Composer authentication files, or environment credentials.

## Catalog and CSV safety

The store scanner calls read-only WooCommerce getters and records only product identity, status/type, administrator display title, configured identifier fields, parent identity, and existing mappings. It never enumerates unrelated metadata. Metadata-key settings are syntax allowlisted and never interpolated into SQL.

Dry-run persistence excludes credentials and raw API bodies. Results include only catalog comparison fields. CSV is streamed directly to the administrator without a permanent file; leading whitespace and control characters are stripped, then cells beginning with `=`, `+`, `-`, or `@` receive a leading apostrophe to prevent formula execution.

Dry-run workflow state and its scheduling outbox use verified InnoDB tables. Action Scheduler storage is replaceable and is treated as an external system: committed intents are dispatched after the plugin transaction, ambiguous dispatches are reconciled by exact arguments, and cancellation is not terminal until matching actions have been verified absent.

Import workflow state follows the same plugin-owned outbox boundary. Production UPC reservations use a single `woocommerce_catalog` namespace across vendor sources and environments; QA uses an isolated `preview:qa` namespace. Before approval, `ready`, and atomic link finalization, a read-only provider directly loads the exact target and streams bounded product/variation pages while retaining only matching UPC and SKU owners. The immutable server-derived manifest binds the target IDs, type, status, parent, identifiers, scoped mapping state, match source, and approval actor.

The existing-link transaction verifies the short-lived HMAC evidence plus run/item/action ownership, logical key, current dispatch generation, execution tokens, leases, scope, environment, catalog identity, UPC reservation, identifier owner sets, and mapping uniqueness. Cancellation serializes on the run lock. Browser-supplied IDs select only persisted dry-run rows and cannot supply the action or target. Audit data is bounded and excludes credentials, HMAC keys, raw vendor payloads, and merchant content. The only durable link mutation is to plugin-owned mapping, catalog-identity, import, and audit tables; WooCommerce products and metadata are read-only.

QA linking is preview-only even when it reaches `applied`. It cannot insert/update authoritative mappings, populate global WooCommerce catalog-identity ownership fields, or satisfy a Production identity fence. QA catalog identities have explicit `preview_only` ownership, while preview UPC/vendor reservation keys remain environment-separated. QA may observe an exact Production mapping without taking ownership; real authoritative conflicts fail closed. The final persistence branch uses the locked run environment, with scope agreement across run/action/item/identity and an environment-bound HMAC proof. Browser fields never select the environment; changing a persisted scope/environment without matching the immutable evidence fails finalization. Production unique constraints and identifier conflict checks are preserved.

## Reporting issues

Report suspected credential exposure privately to the project owner. Do not include tokens, authorization headers, customer payment data, or production payloads in issue reports.
