# IdeaXperts Endless Aisles for WooCommerce

Production-oriented integration for comparing a WooCommerce catalog with Endless Aisles. Milestone 2 adds a durable, read-only catalog dry run. It does **not** import or modify products, synchronize inventory, or submit orders.

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

The **Catalog Dry Run** tab always shows local catalog counts and identifier-field configuration. Starting a remote comparison additionally requires an enabled integration, the QA environment, a configured QA token, and a successful QA connection test. Products and variations are scanned in bounded batches; `GET /api/products` is fetched sequentially at 10 records per page. Action Scheduler jobs are idempotent and resumable, and an active run can be cancelled safely.

Matching identity is: verified existing mapping, one exact normalized UPC, an exact normalized SKU only when enabled, a new-product candidate, or manual review. Titles, descriptions, brands, and prices are never used for identity. Review flags record discontinued, not-purchasable, duplicate, invalid, conflict, ambiguous, and suspicious-price conditions without replacing `already_linked`. Results retain the vendor product-to-options relationship and can be filtered, searched, and exported from a completed run. CSV cells with leading whitespace, control characters, or spreadsheet formula characters are neutralized. No WooCommerce records are changed.

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

The build stages an explicit allowlist, runs `composer install --no-dev --prefer-dist --optimize-autoloader` inside the isolated staging directory, and creates `build/ideaxperts-endless-aisles-0.2.0.zip`. The ZIP includes the production Composer autoloader and excludes tests, development configuration, Git metadata, caches, local environment files, and source-repository secrets. The ignored `build/` directory can be deleted after inspection.

The PHPUnit suite is foundational and uses isolated WordPress function stubs. Full WordPress/WooCommerce integration tests remain future work.

## Data lifecycle

Deactivation removes only plugin-owned scheduled actions, including pending dry-run callbacks. Settings, encrypted credentials, mappings, dry-run reports, logs, synchronization records, and order-submission records remain. There is intentionally no destructive uninstall routine.

See [API contract notes](docs/ENDLESS-AISLES-API.md), [Architecture](docs/ARCHITECTURE.md), [Security](docs/SECURITY.md), and [Changelog](CHANGELOG.md).
