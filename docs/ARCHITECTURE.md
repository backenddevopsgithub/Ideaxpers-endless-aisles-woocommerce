# Architecture

## Composition and lifecycle

`ideaxperts-endless-aisles.php` is the only bootstrap. Composer maps `IdeaXperts\EndlessAisles\` to `src/`. The composition root creates services through a small lazy container only after the WooCommerce runtime class is available. This prevents API, order, product, and scheduling integration from starting without WooCommerce.

Activation applies the schema and creates non-secret defaults. Normal loads apply pending schema versions. A schema version is recorded only after every expected table, required column, index, and InnoDB engine is verified, so partial failures are retried. Deactivation moves active claims into durable recovery, marks their exact outbox intents for cancellation, and performs bounded verified cleanup. It deliberately retains report data.

## Modules

- `Admin`: settings, status, and recent redacted log screens.
- `API`: documented environment URL resolution, strict production guard, size-bounded JSON HTTP client, generic structured failures, a read-only connection test, relative-only bounded product pagination, and a sequential QA-only catalog service with bounded retry/backoff.
- `Catalog`: bounded WooCommerce scanning, UPC/GTIN inspection, strict classification, price review, Action Scheduler orchestration, cancellation/resume, and CSV neutralization.
- `Core`: dependency detection, activation/deactivation, and service composition.
- `Database`: versioned dbDelta-compatible schema.
- `Logging`: structured database logging, recursive redaction, and bounded retention.
- `OrderIntegration`: deterministic order/line-item idempotency keys; no submission transport.
- `ProductMapping`: documented product/size field contracts and string-preserving UPC normalization; no catalog importer.
- `Scheduling`: unique 30-minute inventory action and safe non-importing handler.
- `Security`: authenticated token encryption.
- `Settings`: validated non-secret settings and isolated encrypted credentials.

## Persistence

All tables use the active WordPress prefix:

- `ideaxperts_ea_mappings`: unique WooCommerce and Endless Aisles identities, original/normalized UPC, sync timestamp, and mapping status.
- `ideaxperts_ea_sync_runs`: run type, status, counters, timestamps, and summary.
- `ideaxperts_ea_logs`: level, redacted context JSON, and timestamp.
- `ideaxperts_ea_order_submissions`: unique SHA-256 idempotency key, WooCommerce order ID, remote ID placeholder, attempt state, and timestamps.
- `ideaxperts_ea_dry_runs`: QA run state, administrator, timestamps, cursors, page and inspection progress, counters, cancellation, heartbeat, and sanitized failure summary.
- `ideaxperts_ea_dry_run_items`: one idempotent row per run/vendor product/vendor option, comparison fields, primary classification, review flags, and display-only vendor data.
- `ideaxperts_ea_store_identifiers`: run-scoped, source-labelled WooCommerce UPC/SKU candidates used for bounded matching and duplicate discovery.
- `ideaxperts_ea_dry_run_actions`: durable scheduling intents, immutable intent tokens, claim generations, dispatch attempts, Action Scheduler IDs, and cancellation/reconciliation state.

The `ideaxperts_ea_schema_version` option is `2.3.0`. Migrations preserve earlier data and advance the version/integrity options only after all eight tables, required 2.3.0 columns and indexes, and InnoDB engines are verified.

## Dry-run state machine

A privileged nonce-protected action claims one run only when the integration is enabled, QA is selected, the QA token exists, and the last QA connection test succeeded. Every start or resume creates a cryptographic claim token plus an incrementing immutable claim generation. Store batches run first, followed by one vendor page per callback.

Page persistence, the run transition, completion of the current intent, and creation of the next intent commit together in plugin-owned InnoDB tables. Action Scheduler dispatch happens only after that commit. Because Action Scheduler has a replaceable store, its writes are never treated as part of the plugin transaction. Reconciliation searches by the exact hook, group, intent ID/token, run ID/token/generation, and page; an independently committed action is adopted rather than duplicated if recording its scheduler ID failed.

Cancellation first moves the exact current generation to `cancelling` and marks its intents `cancel_requested`. Bounded reconciliation removes exact pending actions, checks every removal, re-queries, and finalizes `cancelled` only after no matching intent remains. Stale recovery uses the same lifecycle through `recovering` and `failed` without releasing ownership early. A newer generation cannot start during either cleanup state. Maintenance purge continuations also use owned outbox intents rather than anonymous actions.

Heartbeats are written on each callback. Vendor options are upserted by `(run_id, ea_product_id, ea_option_id)`. Child identifier, item, review-flag, aggregate, and cursor changes require an active exact claim in one database transaction. Completed and cancelled runs are retained for 90 days, failed runs for 180 days, active runs are never purged, and the latest completed run per environment is always kept.

Transaction helpers check start, locked reads, every write, commit, and rollback. A failed rollback poisons the repository for the remainder of the request: no later transition, lock release, write, or dispatch is attempted from that database session.

Primary match identity is `already_linked`, `exact_upc_match`, `exact_sku_match`, `new_product_candidate`, or `manual_review`. Lifecycle, duplicate, validity, conflict, ambiguity, and price conditions are stored as review flags and never replace an `already_linked` identity. No classifier invokes a WooCommerce write API. Vendor UPCs are accepted only as JSON strings.

## Deferred work

The product importer, product writes, 24-hour product scheduling, inventory transport/import, order submission, and order cancellation are deferred. Rate limits and a server-side maximum `per_page` are not documented, so dry runs use 10 and conservative local bounds.

## Releases

`composer build-release` creates an isolated, allowlisted staging tree and runs the production Composer install there. The source checkout keeps `vendor/` ignored, while the deployable ZIP includes `vendor/autoload.php`. Tests, development configuration, Git files, caches, local environment files, and build inputs such as Composer manifests are excluded from the final ZIP.
