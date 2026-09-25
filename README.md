# IdeaXperts Endless Aisles for WooCommerce

Production-oriented foundation for connecting Small Town Pets' WooCommerce store to Endless Aisles. Milestone 1 establishes secure configuration, persistence, logging, scheduling, and documented API boundaries. It does **not** import products, synchronize inventory, or submit orders.

## Requirements

- WordPress 6.4 or later
- PHP 8.1 or later with OpenSSL
- WooCommerce 8.0 or later (including its Action Scheduler runtime)
- Composer 2 for installation and development

## Install

Place this repository in `wp-content/plugins/ideaxperts-endless-aisles`, run `composer install --no-dev --classmap-authoritative`, then activate **IdeaXperts Endless Aisles for WooCommerce**. Settings, status, and redacted logs appear under **WooCommerce → Endless Aisles**.

The documented QA and Production base URLs are built in. API requests use `X-EA-REQUEST-TOKEN`; Bearer authentication is not used. QA is the default. Production requests remain blocked unless Production is selected and the separate production safeguard is explicitly confirmed. The connection test is an authenticated, read-only request for one product-list record and stores no response data.

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

The build stages an explicit allowlist, runs `composer install --no-dev --prefer-dist --optimize-autoloader` inside the isolated staging directory, and creates `build/ideaxperts-endless-aisles-0.1.0.zip`. The ZIP includes the production Composer autoloader and excludes tests, development configuration, Git metadata, caches, local environment files, and source-repository secrets. The ignored `build/` directory can be deleted after inspection.

The PHPUnit suite is foundational and uses isolated WordPress function stubs. Full WordPress/WooCommerce integration tests remain future work.

## Data lifecycle

Deactivation removes only plugin-owned scheduled actions. Settings, encrypted credentials, mappings, logs, synchronization records, and order-submission records remain. There is intentionally no destructive uninstall routine.

See [API contract notes](docs/ENDLESS-AISLES-API.md), [Architecture](docs/ARCHITECTURE.md), [Security](docs/SECURITY.md), and [Changelog](CHANGELOG.md).
