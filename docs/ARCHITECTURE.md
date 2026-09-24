# Architecture

## Composition and lifecycle

`ideaxperts-endless-aisles.php` is the only bootstrap. Composer maps `IdeaXperts\EndlessAisles\` to `src/`. The composition root creates services through a small lazy container only after the WooCommerce runtime class is available. This prevents API, order, product, and scheduling integration from starting without WooCommerce.

Activation applies the schema and creates non-secret defaults. Normal loads apply pending schema versions. A schema version is recorded only after every expected table is present, so partial failures are retried. Deactivation unschedules only this plugin's Action Scheduler group and hook; it deliberately retains all data.

## Modules

- `Admin`: settings, status, and recent redacted log screens.
- `API`: documented environment URL resolution, strict production guard, size-bounded JSON HTTP client, generic structured failures, a read-only connection test with exact envelope validation, and relative-only bounded product pagination.
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

The `ideaxperts_ea_schema_version` option tracks schema state. New imported products are fixed to `draft` for this milestone.

## Deferred work

The full product importer, 24-hour product scheduling, inventory transport/import, order submission, and cancellation are deferred. Cancellation remains blocked by contradictory documented methods. Rate limits and a server-side maximum `per_page` are not documented. See `ENDLESS-AISLES-API.md` for the reviewed contract and remaining ambiguities.

## Releases

`composer build-release` creates an isolated, allowlisted staging tree and runs the production Composer install there. The source checkout keeps `vendor/` ignored, while the deployable ZIP includes `vendor/autoload.php`. Tests, development configuration, Git files, caches, local environment files, and build inputs such as Composer manifests are excluded from the final ZIP.
