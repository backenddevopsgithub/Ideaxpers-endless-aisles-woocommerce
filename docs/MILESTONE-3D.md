# Milestone 3D: pricing configuration and Production preview

## A. Repository assessment

The initial checkout was clean on `feature/catalog-pricing-production-preview`. Code inspection covered the pricing interface, projection, creation orchestrator, import policy/manager/repository, immutable approval manifest, Catalog Import and dry-run admin flows, API environment guards, classification, source persistence, and Milestone 3C documentation/regressions.

Milestone 3C already binds the exact sanitized Draft/simple projection, policy ID/version/configuration hash, source identity, vendor snapshot, environment and approval generation. It already fences execution and recovery, performs one external save, and settles mappings/counters atomically. It lacked a concrete configurable pricing implementation and a Production remote dry-run entry point. Its admin candidate list always reported missing pricing; it allowed preparing a Production creation approval without a price (execution still blocked).

## B. Pricing-policy design

The repository documents vendor option `price`, `wholesale`, `minimum_advertised_price`, and `msrp` as reporting inputs only. Dry runs persist the first three as `retail_price`, `wholesale_price`, and `map_price`; MSRP is inspected but not persisted. There is **no approved business selling-price rule**, currency contract, markup or margin. Existing store prices are not creation-policy inputs.

`ConfiguredCreationPricingPolicy` is an opt-in framework for a merchant-approved **direct-source** rule. Its ID is `ea-approved-direct-source`, version `1`. It exposes sorted nonsecret configuration, canonical SHA-256, resolved regular price, and explicit failure codes. With an approved direct-source decision, price equals the selected persisted source, normalized to the approved store precision; it performs no markup, currency conversion, inferred MAP adjustment, or rounding. An actual markup rule would require a separately specified implementation and approval.

Configuration is supplied only through the server constant `IDEAXPERTS_EA_CREATION_PRICING`; this milestone does not define it or configure a merchant policy. Required keys are:

- `approval_reference`: nonempty identifier of the actual merchant decision (configuration is trusted server code, not a browser approval).
- `rule`: `direct_source`.
- `source_field`: explicitly approved `retail_price`, `wholesale_price`, or `map_price`.
- `currency`: explicitly approved uppercase three-letter currency, matching `woocommerce_currency`.
- `decimal_places`: integer 0–4, matching WooCommerce store precision.
- `max_age_seconds`: positive integer approved source observation lifetime.
- Optional `minimum_price` / `maximum_price`: approved positive decimal bounds.

Prices use string validation, normalization and padded decimal comparison without binary floating-point arithmetic. Missing, non-string, malformed, zero/negative, oversized, excessive-precision, currency/precision-mismatched, out-of-bounds and stale/future/undated inputs fail closed. Source observation time is the dry-run item's persisted `created_at`, bound into the immutable vendor snapshot. Execution uses that approved persisted snapshot; this milestone does not introduce a per-option live vendor polling endpoint. Source freshness is bounded by the configured observation lifetime; refresh the Production dry run for a new vendor observation.

## C. Business decision required

The merchant must approve the exact selling-price source/rule, vendor currency assumption, store precision, source observation lifetime, optional bounds, and an approval reference. No values are selected on the merchant's behalf. If the desired rule is not a direct-source rule, specify it before enabling a different policy implementation. **Production creation remains blocked with `pricing_policy_missing` in the default configuration.**

## D. Production preview workflow

Catalog Import contains a nonce/capability-protected **Start read-only Production preview** action. It requires enabled Production settings, the existing Production confirmation safeguard, a Production credential, a successful selected-environment connection test, and the existing single-active-run lock. The existing bounded store scan, paginated GET catalog fetch, retry, cancellation, retention and durable dry-run scheduling infrastructure is reused. Catalog callbacks derive their environment from the persisted run; changing the active environment fails closed rather than fetching the other credential/base URL.

Connection tests now persist separate QA/Production readiness, and credential changes invalidate only the corresponding readiness record. Preview writes only scoped, non-authoritative dry-run/scheduling records. `CreationPreview` has no writer, import repository, ownership reservation or scheduling dependency. It reads current live catalog state and projects persisted candidate inputs. Rows show identity, title, description summary, UPC, classification, vendor price inputs, resolved price/policy, mapping state and blocking reason. Failed inspection blocks only that candidate. Rendering a projection creates no approval or import run.

## E. Approval workflow

Select the explicit candidate, review its server-generated price, description, normalized UPC, policy configuration/hash and desired field hash, then confirm the user-scoped expiring manifest with the existing action-specific nonce. A Production selection may contain at most **one new creation**. Both prepare and confirm enforce the bound and reject blocked/unpriced candidates. Existing-product link approval remains available through Milestone 3B's flow.

Confirmation rendering rebuilds the manifest and reports current versus stale/blocked projection. Confirmation rebuilds it again before recording approval. The manifest now retains all supported pricing source fields and observation time; configured policy configuration is also retained in the creation binding. No browser request supplies writable content or final price. The original worker/final-permit comparison still rejects policy, price, configuration, title, description, UPC or vendor-snapshot drift with `approval_projection_changed`. Workers never refresh approvals. Fresh preview and explicit fresh approval are required.

## F. Files changed

New production code: `src/Import/ConfiguredCreationPricingPolicy.php`, `src/Import/CreationPreview.php`.

Updated production code: `src/API/CatalogService.php`, `src/API/ConnectionTester.php`, `src/Admin/CatalogImportAdmin.php`, `src/Admin/CatalogDryRunAdmin.php`, `src/Catalog/DryRunManager.php`, `src/Database/ImportRepository.php`, `src/Import/ApprovalManifest.php`, `src/Import/LiveCatalogStateProvider.php`, `src/Import/SimpleProductCreation.php`, `src/Import/SimpleProductProjection.php`, `src/Plugin.php`, `src/Settings/SettingsRepository.php`.

New tests: `tests/Import/ConfiguredCreationPricingPolicyTest.php`, `tests/Import/CreationPreviewTest.php`, `tests/Import/ProductionApprovalSelectionTest.php`.

Updated tests: `tests/API/CatalogServiceTest.php`, `tests/API/ConnectionTesterTest.php`, `tests/Catalog/DryRunManagerTest.php`, `tests/Import/SimpleProductCreationTest.php`. This document records the audit and validation.

## G. Safety boundaries

- Preview: zero Woo saves, authoritative mappings, vendor-created ownership, import approvals, import counters or Production reservations. QA never gains Production write authority.
- Execution: exact explicit manifest approval; one new Draft per Production admin approval; existing M3C writer, operation correlation, creator/recovery token fencing, 300-second leases, recurring recovery, five-attempt 60/120/240/300/300 backoff, retry exhaustion, one-save and atomic settlement/cancellation semantics.
- Exact mappings, UPC owners, approved SKU match owners, review flags, discontinued/non-purchasable inputs, inspection failures and missing/stale pricing block new creation. Live SKU inspection and final-permit checks now also block an existing exact SKU fallback when that matching setting is enabled.
- Recovery uses the original approval and correlated Draft, independent of current pricing configuration. It never authorizes another external save.
- No production WooCommerce catalog, deployment, dependency installation/update, commit, or push occurred.

## H. Tests

50 additional test cases cover deterministic policy identity/configuration hashes/decimal normalization, invalid/missing sources, currency/precision/configuration errors, optional price bounds, stale/future observations, read-only Production projection, QA/environment isolation, existing mapping/UPC/SKU protection, per-candidate inspection failure, one-priced-candidate approval, multiple-candidate rejection, missing-policy rejection, source-price drift, configuration drift, fresh approval at price B, Production GET credential/base URL separation, safeguard rejection and Production preview cancellation. Production connection readiness and credential invalidation gain additional assertions. Existing title/description/UPC drift, cancellation, M3B linking and all M3C recovery regressions remain enabled.

## I. Validation

Using existing dependencies only:

- PHPUnit: **699 tests, 5,358 assertions**, all passed (baseline: 649 / 5,212).
- PHP syntax: 102 files, passed.
- PHPCS: 54 configured production/tool files, zero errors or warnings.
- PHPStan: no errors.
- `composer validate --strict`: passed.
- `composer audit --locked --no-interaction`: no security vulnerability advisories.
- `git diff --check`: passed.

## J. Remaining blockers

The business pricing decision/server configuration and exact operator approval are prerequisites to any real Production creation. Real WordPress/WooCommerce sanitizer/API behavior, MySQL concurrent transactions/unique locks, external catalog races, Action Scheduler worker-death recovery and cron availability remain integration-validation requirements from Milestone 3C. The deterministic tests use fake Woo persistence and an in-memory database; they do not prove those live integration properties. This milestone enables review and approval infrastructure, not a bulk Production import or a live catalog write.

## K. Git state

Branch: `feature/catalog-pricing-production-preview`. All milestone changes are unstaged/uncommitted. No commit or push was performed. See the delivery response for the exact final `git status --short` output.
