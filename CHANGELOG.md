# Changelog

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
