# Architecture

## Composition and lifecycle

`ideaxperts-endless-aisles.php` is the only bootstrap. Composer maps `IdeaXperts\EndlessAisles\` to `src/`. The composition root creates services through a small lazy container only after the WooCommerce runtime class is available. This prevents API, order, product, and scheduling integration from starting without WooCommerce.

Activation applies the schema and creates non-secret defaults. Normal loads apply pending schema versions. A schema version is recorded only after every expected table is present, so partial failures are retried. Deactivation unschedules only this plugin's Action Scheduler group and hook; dry-run jobs are unscheduled by hook so nonempty `[run_id, page]` arguments are included. It deliberately retains all data.

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

The `ideaxperts_ea_schema_version` option is `2.1.0`. Migrations preserve the four Milestone 1 tables and advance the option only after all seven expected tables exist and the Milestone 2 columns `last_heartbeat_at` and `review_flags` are present.

## Dry-run state machine

A privileged nonce-protected action claims one run only when the integration is enabled, QA is selected, the QA token exists, and the last QA connection test succeeded. Store batches run first, followed by one vendor page per callback. Progress updates use conditional status transitions, so cancellation cannot be overwritten by an in-flight batch. Heartbeats are written on each callback; active runs with no heartbeat for six hours are marked failed and their owned jobs and lock are released. Vendor options are upserted by `(run_id, ea_product_id, ea_option_id)`. Child identifier, item, and review-flag writes require an active parent run in the same database transaction. Store matches are unique by WooCommerce product and variation identity. Completed and cancelled runs are retained for 90 days, failed runs for 180 days, active runs are never purged, and the latest completed run per environment is always kept. Retention deletes a bounded batch and schedules a unique continuation when more expired rows remain. Administrators can run the same nonce-protected bounded purge. The active-run lock is a compare-and-swap owner token.

Primary match identity is `already_linked`, `exact_upc_match`, `exact_sku_match`, `new_product_candidate`, or `manual_review`. Lifecycle, duplicate, validity, conflict, ambiguity, and price conditions are stored as review flags and never replace an `already_linked` identity. No classifier invokes a WooCommerce write API. Vendor UPCs are accepted only as JSON strings.

## Deferred work

The product importer, product writes, 24-hour product scheduling, inventory transport/import, order submission, and order cancellation are deferred. Rate limits and a server-side maximum `per_page` are not documented, so dry runs use 10 and conservative local bounds.

## Releases

`composer build-release` creates an isolated, allowlisted staging tree and runs the production Composer install there. The source checkout keeps `vendor/` ignored, while the deployable ZIP includes `vendor/autoload.php`. Tests, development configuration, Git files, caches, local environment files, and build inputs such as Composer manifests are excluded from the final ZIP.
