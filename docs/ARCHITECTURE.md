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
- `Import`: immutable approvals, deterministic eligibility, source-scoped identity reservations, final-write permits, cancellation fencing, and durable job orchestration; no WooCommerce writer.
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
- `ideaxperts_ea_dry_runs`: source/environment-scoped run state, administrator, timestamps, cursors, page and inspection progress, counters, cancellation, heartbeat, and sanitized failure summary.
- `ideaxperts_ea_dry_run_items`: one idempotent row per run/vendor product/vendor option, comparison fields, primary classification, review flags, and display-only vendor data.
- `ideaxperts_ea_store_identifiers`: run-scoped, source-labelled WooCommerce UPC/SKU candidates used for bounded matching and duplicate discovery.
- `ideaxperts_ea_dry_run_actions`: source/environment-bound durable scheduling intents, immutable intent tokens, claim generations, dispatch attempts, Action Scheduler IDs, and cancellation/reconciliation state.
- `ideaxperts_ea_import_runs`: immutable manifest identity, source/environment, dry-run generation, policy/settings hashes, lifecycle, cancellation, and counters.
- `ideaxperts_ea_import_items`: approved item/action identity, expected freshness hashes, lease ownership, write-permit and reconciliation state, and bounded failure detail.
- `ideaxperts_ea_catalog_identities`: one source/environment-scoped owner for each vendor product and option identity.
- `ideaxperts_ea_store_identifier_reservations`: atomic UPC ownership in the store-wide Production namespace or isolated QA preview namespace.
- `ideaxperts_ea_import_actions`: source/environment-bound durable import scheduling intents, immutable tokens, dispatch reconciliation, execution claims, and cancellation fences.
- `ideaxperts_ea_import_events`: bounded append-only import audit events without credentials or raw payloads.
- `ideaxperts_ea_vendor_snapshots`: bounded canonical vendor snapshots used for freshness checks without retaining raw responses.

The `ideaxperts_ea_schema_version` option is `3.0.0`. Migrations preserve earlier data and advance the version/integrity options only after all 15 tables, required 3.0.0 columns and indexes, and InnoDB engines are verified.

## Dry-run state machine

A privileged nonce-protected action claims one run only when the integration is enabled, QA is selected, the QA token exists, and the last QA connection test succeeded. Every start or resume creates a cryptographic claim token plus an incrementing immutable claim generation. Store batches run first, followed by one vendor page per callback.

Page persistence, the run transition, completion of the current intent, and creation of the next intent commit together in plugin-owned InnoDB tables. Action Scheduler dispatch happens only after that commit. Because Action Scheduler has a replaceable store, its writes are never treated as part of the plugin transaction. Reconciliation searches by the exact hook, group, intent ID/token, run ID/token/generation, and page; an independently committed action is adopted rather than duplicated if recording its scheduler ID failed.

Cancellation first moves the exact current generation to `cancelling` and marks its intents `cancel_requested`. Bounded reconciliation removes exact pending actions, checks every removal, re-queries, and finalizes `cancelled` only after no matching intent remains. Stale recovery uses the same lifecycle through `recovering` and `failed` without releasing ownership early. A newer generation cannot start during either cleanup state. Maintenance purge continuations also use owned outbox intents rather than anonymous actions.

Heartbeats are written on each callback. Vendor options are upserted by `(run_id, ea_product_id, ea_option_id)`. Child identifier, item, review-flag, aggregate, and cursor changes require an active exact claim in one database transaction. Completed and cancelled runs are retained for 90 days, failed runs for 180 days, active runs are never purged, and the latest completed run per environment is always kept.

Transaction helpers check start, locked reads, every write, commit, and rollback. A failed rollback poisons the repository for the remainder of the request: no later transition, lock release, write, or dispatch is attempted from that database session.

Primary match identity is `already_linked`, `exact_upc_match`, `exact_sku_match`, `new_product_candidate`, or `manual_review`. Lifecycle, duplicate, validity, conflict, ambiguity, and price conditions are stored as review flags and never replace an `already_linked` identity. No classifier invokes a WooCommerce write API. Vendor UPCs are accepted only as JSON strings.

## Import foundation state machine

An administrator first prepares a server-generated manifest from a completed dry run. The canonical manifest binds its source scope, environment, dry-run generation, settings and policy versions, selected item identities, approved actions, any persisted manual target, and expected vendor/local/mapping hashes. Confirmation uses an expiring server-side token and re-verifies the manifest hash; clients cannot promote blocked rows or supply action types.

Initial import actions are provisioned while the run remains `queued`. Their logical keys are deterministic, so retry adopts exact existing rows and fills only missing rows; the run becomes `running` only after the complete initial outbox exists. Each dispatch increments a durable generation included in the immutable callback arguments. Reconciliation matches scheduler ID, hook, group, arguments, logical key, and generation; it retains a live exact scheduler action and compare-and-swap reopens a lost, failed, or cancelled one. Old-generation callbacks cannot claim the current row.

Workers claim a leased item, atomically reserve the source-scoped vendor identity and environment-appropriate UPC namespace, and compute a read-only live fingerprint from current WooCommerce products/variations, configured UPC fields, relevant SKU, target type/status/parent, store-wide identifier owners, and scoped mapping rows. Targets are loaded directly by ID; products and variations are streamed in separate bounded pages while retaining only matching UPC/SKU owner identities. The immutable manifest also records the approved target and mapping state. Acceptance persists a keyed, context-bound freshness proof over the item, run, source, environment, approval generation, live hash, and timestamp.

For an explicitly approved `exact_upc_match` or `exact_sku_match`, one transaction locks `run -> import action -> item -> catalog identity -> UPC reservation -> mapping rows`. It proves the current logical action, dispatch generation, execution token and lease, approval/freshness evidence, reservations, exact target/type/parent, identifier ownership, and mapping uniqueness. Production then binds the catalog identity as `linked_existing` and creates or adopts the exact mapping; QA records `preview_only` success without a mapping or global Woo ownership. Both record the bounded audit event, move the item to `applied`, complete the action, and update the run count before committing. Stale targets settle to `stale_snapshot`; mapping ownership conflicts settle to `manual_required`. No WooCommerce object is mutated.

The 2.3.0-to-3.0.0 upgrade is explicit and rerunnable. Repository history establishes that pre-3.0 catalog data was QA-only, so unscoped/`legacy` mapping rows are backfilled to `endless-aisles:qa` and `qa`; dry-run children inherit their run scope. The migration detects ambiguous normalized mapping identities, explicitly replaces the mapping identity index, verifies its final physical definition, and refuses to advance the schema version when any backfill or verification is incomplete.

Import transaction locking follows compatible canonical orders. Existing-link finalization uses `run → import action → item → catalog identity → UPC reservation → mapping rows`; catalog-only paths use the applicable suffix beginning at `item`, and scheduler-only paths use `run → import action`. Cancellation holds the run lock, updates actions before items, and never acquires an earlier lock after a later one. Audit rows are append-only inserts after the authoritative locks. No path locks an identity, reservation, or mapping and then attempts to lock an item, action, or run.

`applying` is never reclaimed as an ordinary expired lease. Its durable reconciliation requirement advances it through `reconciling` or `manual_recovery`, preventing blind replay after an ambiguous future write. Cancellation moves the run to `cancelling`, cancels only pre-permit items, and marks active durable actions `cancel_requested`. Bounded reconciliation neutralizes and verifies each exact scheduler delivery before settling the durable row to `cancelled`; in-progress or actively leased executions remain fenced until ownership resolves. The run becomes terminal only when no item remains in `applying` or `reconciling` and no actionable durable import action remains.

The isolated PHPUnit database fake proves deterministic transition, token-fencing, rollback, ordering, uniqueness, and no-partial-link behavior, but it is not a substitute for integration coverage. Live MySQL/WordPress tests are still required for real row-lock contention, deadlocks, rollback behavior, simultaneous unique-key conflicts, Action Scheduler cancellation/redelivery, WooCommerce variation lookup, configured UPC/global-ID behavior, and duplicate identifier behavior across supported versions.

Existing-link SKU identity uses `UpcNormalizer::normalize()` throughout scanning, approval, live owner discovery, and final validation: trim and remove whitespace/hyphens, preserve leading zeroes and case, and never convert to a number. Inspection retains `sku` as raw drift/audit evidence and `normalized_sku` for identity. Both enter the target fingerprint, while owner discovery compares normalized values across every product and variation page. Final linking requires exactly the approved owner and still enforces UPC conflicts.

Variation inspection loads the child and its current parent directly, without loading siblings. Approval and finalization require the approved parent ID, an existing variable parent, and a valid non-trash product status. Parent ID, type, and status enter the canonical target fingerprint. Existing approvals without this evidence fail closed and require reapproval.

Mapping freshness at finalization compares normalized authoritative fields from the actual `SELECT ... FOR UPDATE` rows against approval: source scope, environment, EA product/option, WooCommerce product/variation, active status, and normalized UPC. Row IDs and timestamps are not identity. Mapping rows do not persist ownership mode; catalog identity ownership is separately fenced as `linked_existing`. A deleted approved mapping is never recreated, and a changed mapping is never redirected. The sole adoption exception is an initially absent mapping becoming exactly the intended active mapping with every authoritative field matching.

For link and create work (the durable callback remains `validate`), completion is allowed only for item states `applied`, `stale_snapshot`, `manual_required`, `blocked`, `cancelled`, and `manual_recovery`. It is not allowed for `pending`, `leased`, `validating`, `ready`, `applying`, `reconciling`, or `retry_wait`. Settlement locks run, action, then item and checks the current execution owner and lease before deciding. Creation's external-write states are recovered through exact correlation, never reset for another save. See [Milestone 3C](MILESTONE-3C.md).

A crash after `ready` leaves finalization unfinished. If the action lease expires first, a replacement callback defers its durable action to `retry_wait`, available no earlier than the item lease expiry and at least 30 seconds later; it does not steal the item or consume a dispatch-failure attempt. Reconciliation reclaims expired pre-apply items before redispatch. If the item lease expires first, its `retry_wait` state remains recoverable until the action lease expires. When both have expired, the new callback claims the item with a new token, reacquires reservations, revalidates freshness, and finalizes the exact link. Cancellation, stale generations, and mapping drift remain fenced during this window. Recovery depends on the existing reconciliation entry point running; it does not busy-loop.

## QA preview persistence and Production ownership

Existing-link validation and transaction settlement share one state machine. The locked run environment selects persistence after run/action/item/identity scope agreement and environment-bound freshness validation. QA identities use `ownership_mode = preview_only` from reservation onward; successful finalization releases the identity and UPC reservations with `reservation_status = released`, leaves `wc_identity_key`, `wc_product_id`, and `wc_variation_id` NULL, and never inserts or updates a mapping. Target IDs stay on the import item and bounded `preview_link_succeeded` audit event, together with EA identity, operation UUID, match type, source/environment, approval evidence, freshness hashes, and timestamp. The item becomes `applied`, the action `completed`, and its run's `applied_count` increases once in the same transaction. Duplicate callbacks fail the existing token/status fence before any mutation; failures roll back all settlement state.

Production alone creates/adopts authoritative mappings and binds `linked_existing` catalog identity ownership to the installation-wide SHA-256 key of `product_id:variation_id`. Mapping unique indexes `(source_scope,ea_product_id,ea_option_id)` and `(wc_product_id,wc_variation_id)`, catalog identity vendor/operation uniqueness, and global `wc_identity_key` uniqueness remain unchanged. Multiple NULL Woo identity keys are valid. QA vendor reservations remain scoped to `endless-aisles:qa`; UPC reservations remain in `preview:qa`, distinct from Production `woocommerce_catalog`. No schema migration or preview mapping table is required.

QA live approval/freshness observes relevant Production mappings. Finalization still locks all mappings occupying the target and Production vendor identity. An exact active Production mapping for the same EA identity/target is read-only already-linked context for QA; it can complete a preview without altering any authoritative row/key. A conflicting mapping reports `manual_required / mapping_conflict`. Production cannot adopt a QA mapping or preview identity. Dry-run classification consults mapping rows, not preview history: a preview does not create `already_linked`; normal Production mappings remain recognizable in Production dry runs. The existing remote dry-run entry point remains QA-only; deterministic tests construct completed Production dry-run inputs for the deferred Production entry point.

Historical authoritative QA mapping rows from older code are not silently deleted or promoted. They fail closed as conflicts and require explicit reconciliation before using an occupied target in Production. This change prevents new QA ownership claims; it does not destructively rewrite prior data.

## Deferred work

An approved Production pricing rule and Production dry-run entry point remain prerequisites for operational new simple Draft creation. Variation creation, merchant product-field writes, image/media handling, 24-hour product scheduling, inventory transport/import, shipping, order submission, and order cancellation are deferred. Rate limits and a server-side maximum `per_page` are not documented, so dry runs use 10 and conservative local bounds.

## Releases

`composer build-release` creates an isolated, allowlisted staging tree and runs the production Composer install there. The source checkout keeps `vendor/` ignored, while the deployable ZIP includes `vendor/autoload.php`. Tests, development configuration, Git files, caches, local environment files, and build inputs such as Composer manifests are excluded from the final ZIP.

QA reservations remain active through validation, ready-state crashes, retries, and unresolved cancellation. Safe terminal outcomes (applied, stale snapshot, manual required, blocked) release only QA preview owners in the settlement transaction. Cancelled items release only after cancellation is authoritative; applying, reconciling, and manual recovery remain reserved. Later independent QA runs atomically reuse released rows with a new owning item, operation UUID, and token. Active contention defers the item/action for retry without exhausting dispatch attempts. Import item and manifest history plus audit events preserve prior previews independently of reservation reuse.

ImportAuditPayload allowlists every event payload, canonicalizes keys, measures actual UTF-8 JSON bytes, and replaces values above 128 encoded bytes with canonical SHA-256 and byte-count evidence. A core-field fallback preserves a hash of the compacted context if the 4,000-byte event budget is exceeded. Success events use compact Woo target references, prior mapping count/hash, catalog identity ID, mapping-created/adopted flags, environment, approval generation, and freshness hashes; complete EA and operation identities remain in event columns and manifest/item state. Required events commit atomically with settlement.
