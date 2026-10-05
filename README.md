# IdeaXperts Endless Aisles for WooCommerce

Production-oriented integration for comparing a WooCommerce catalog with Endless Aisles. Milestone 3C adds a guarded simple Draft creation and recovery path for approved new candidates, alongside 3B existing-product linking. Production creation currently blocks with `pricing_policy_missing`: this repository contains no approved Production pricing rule. QA can preview new candidates without catalog mutation. Inventory synchronization and order submission remain disabled.

## Requirements

- WordPress 6.4 or later
- PHP 8.1 or later with OpenSSL
- WooCommerce 8.0 or later (including its Action Scheduler runtime)
- Composer 2 for installation and development

## Install

Place this repository in `wp-content/plugins/ideaxperts-endless-aisles`, run `composer install --no-dev --classmap-authoritative`, then activate **IdeaXperts Endless Aisles for WooCommerce**. Settings, status, and redacted logs appear under **WooCommerce → Endless Aisles**.

The documented QA and Production base URLs are built in. API requests use `X-EA-REQUEST-TOKEN`; Bearer authentication is not used. QA is the default. Production requests remain blocked unless Production is selected and the separate production safeguard is explicitly confirmed. The connection test is an authenticated, read-only request for one product-list record and stores no response data.

## Catalog dry run

Configure the WooCommerce global unique ID, any additional UPC metadata keys, and (only if explicitly desired) exact SKU-to-UPC matching under **WooCommerce → Endless Aisles → Settings**. Metadata keys accept only letters, numbers, underscores, dots, colons, and hyphens. Numeric SKUs are ignored unless exact SKU matching is enabled.

The **Catalog Dry Run** tab always shows local catalog counts and identifier-field configuration. Starting a remote comparison additionally requires an enabled integration, the QA environment, a configured QA token, and a successful QA connection test. Products and variations are scanned in bounded batches; `GET /api/products` is fetched sequentially at 10 records per page. Scheduling is recorded first in a plugin-owned InnoDB outbox, then reconciled with Action Scheduler, so correctness does not depend on Action Scheduler sharing the plugin transaction. Jobs are generation-owned, idempotent, resumable, and cancelled through a verified cleanup lifecycle.

Matching identity is: verified existing mapping, one exact normalized UPC, an exact normalized SKU only when enabled, a new-product candidate, or manual review. Titles, descriptions, brands, and prices are never used for identity. Review flags record discontinued, not-purchasable, duplicate, invalid, conflict, ambiguous, and suspicious-price conditions without replacing `already_linked`. Results retain the vendor product-to-options relationship and can be filtered, searched, and exported from a completed run. CSV cells with leading whitespace, control characters, or spreadsheet formula characters are neutralized. No WooCommerce records are changed.

## Existing-product linking

Administrators can prepare an immutable approval manifest from a completed QA or Production dry run. Existing exact UPC and exact SKU candidates require an explicit per-item checkbox and a second confirmation through an expiring server-side token. The server rebuilds the manifest from persisted dry-run rows; browser-supplied action and target values are never authoritative.

Link workers re-read the exact target and bounded UPC/SKU owner sets, verify the approved type, parent, identifiers, mapping state, freshness evidence, reservations, and current action execution. Production then atomically creates or adopts the authoritative plugin-owned mapping. QA instead records a preview-only successful result: no mapping row, global WooCommerce identity key, or Production ownership is created. Both settle the item to `applied` and count success exactly once. For QA, `applied` means **preview link succeeded**. A later Production approval uses its own target and authoritative state, independently of QA preview history.

QA can observe an exact Production mapping as read-only context and successfully preview it without modifying or adopting its ownership. Conflicting authoritative mappings still require manual review. QA preview history is retained in immutable manifests, import items, operation references, and audit events, never used to classify a real mapping as `already_linked`. Administrator selection, confirmation, and run summaries label QA as **Preview only**.

QA identity and UPC reservations protect active work, including retries and crash recovery. Settled previews release their reservations atomically; a later independent QA approval can reuse them. Active contention waits for a retry. Cancellation releases reservations only after authoritative settlement; ambiguous applying/reconciling/manual-recovery work retains them. Production ownership remains durable. All import audit JSON uses one deterministic allowlist and a 4,000-byte budget, with compact target references and canonical hashes instead of full mapping rows.

The existing product remains merchant-owned: titles, descriptions, identifiers, prices, stock, status, taxonomy, shipping data, dimensions, and media are untouched. New simple Draft creation has its own committed-intent/reconciliation workflow; see [Milestone 3C](docs/MILESTONE-3C.md). Variation creation, images, inventory synchronization, shipping, and order submission remain disabled.

## Development

```bash
composer validate --strict
composer install --no-interaction --prefer-dist --no-progress
composer audit --locked --no-interaction
composer lint
composer cs
composer phpstan
composer test
```

These are the same checks used by CI. The locked dependency audit fails when Composer reports a known security advisory. After dependencies are installed, `composer check` is the equivalent single-command shortcut for strict Composer validation, syntax checks, WordPress coding standards, PHPStan, and PHPUnit using the committed `composer.lock`; run the audit command separately before release work.

## Release package

Build the deployable plugin ZIP on Windows, macOS, or Linux with:

```bash
composer build-release
```

The build stages an explicit allowlist, runs `composer install --no-dev --prefer-dist --optimize-autoloader` inside the isolated staging directory, and creates `build/ideaxperts-endless-aisles-0.3.0.zip`. The ZIP includes the production Composer autoloader and excludes tests, development configuration, Git metadata, caches, local environment files, and source-repository secrets. The ignored `build/` directory can be deleted after inspection.

The PHPUnit suite is foundational and uses isolated WordPress function stubs. Full WordPress/WooCommerce integration tests remain future work.

## Data lifecycle

Deactivation removes only plugin-owned scheduled actions, including pending dry-run callbacks. Settings, encrypted credentials, mappings, dry-run reports, logs, synchronization records, and order-submission records remain. There is intentionally no destructive uninstall routine.

See [API contract notes](docs/ENDLESS-AISLES-API.md), [Architecture](docs/ARCHITECTURE.md), [Security](docs/SECURITY.md), and [Changelog](CHANGELOG.md).
