# Changelog

## Unreleased

- Added schema 3.0.0 and Milestone 3A's immutable approval manifests, deterministic import policy, source/environment isolation, and server-side eligibility derivation.
- Added durable import runs/items, vendor snapshots, catalog identity and UPC reservations, audit events, and an Action Scheduler outbox with exact-argument reconciliation.
- Added final-write permit, applying/reconciling/manual-recovery, cancellation-fence, lease, and token-ownership infrastructure while deliberately leaving all WooCommerce product writes disabled.
- Added a minimal nonce- and capability-protected import preparation/confirmation/cancellation screen and import-foundation test coverage.
- Added authoritative read-only WooCommerce/mapping freshness fingerprints, context-bound final-permit evidence, resumable action provisioning, dispatch-generation fencing, and lost-scheduler-action recovery.
- Added an explicit fail-closed 2.3.0-to-3.0.0 populated-data migration and unambiguous canonical JSON vendor identity hashing.
- Added schema 2.3.0 with a durable InnoDB dry-run scheduling outbox and immutable claim generations.
- Separated plugin transactions from replaceable Action Scheduler storage, with exact-argument adoption and bounded reconciliation after ambiguous dispatches.
- Added durable `cancelling` and `recovering` lifecycles, verified bounded unscheduling, stale-claim isolation, and generation-bound administrator cancellation.
- Centralized rollback handling, poisoned failed database sessions, and made catalog aggregate-query failures abort page commits.

## 0.2.0 - 2026-09-25

- Added QA-only, read-only Endless Aisles catalog dry runs using sequential `GET /api/products` pages.
- Added bounded WooCommerce product/variation scanning with configurable global unique ID, metadata-key, and opt-in exact SKU matching.
- Added strict UPC/GTIN normalization and validation, deterministic matching classifications, duplicate detection, and suspicious-price review.
- Added versioned durable run, result, and store-identifier tables with idempotent items, resumable progress, cancellation, and concurrent-run prevention.
- Added Action Scheduler processing, administrator progress/results controls, and streamed formula-safe CSV export.
- Added audit events, security hardening, documentation, and automated coverage for matching, prices, retries, QA enforcement, workflow cancellation, retention, and CSV safety.
- Separated match identity from review flags, rejected numeric vendor UPCs, isolated QA connection status, and bounded dry-run retention with heartbeats and conditional cancellation.
- Gated dry-run child writes to active runs, compare-and-swap lock ownership, WooCommerce-identity match deduplication, formula-safe CSV preservation, QA token-reset, review-flag allowlisting, and schema column verification.

## 0.1.0 - 2026-09-24

- Added the Milestone 1 plugin bootstrap and WooCommerce dependency gate.
- Added encrypted environment-specific credential storage and validated settings.
- Aligned API URLs, authentication, endpoint registry, safe connection testing, product contracts, and pagination foundations with the official Endless Aisles documentation.
- Hardened response-size bounds, timeout classification, pagination references, Production confirmation, connection-envelope validation, and serialized-header redaction.
- Added dbDelta schemas for mappings, sync runs, structured logs, and idempotent order submissions.
- Added bounded redacted logging, Action Scheduler registration, status UI, developer tooling, and foundational tests.
