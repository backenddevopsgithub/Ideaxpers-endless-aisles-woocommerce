# Milestone 3C: simple Draft creation

## Scope and pricing

The implementation adds a narrow `SimpleProductCreation` orchestrator, sanitized `SimpleProductProjection`, `SimpleProductWriterInterface`, and the single-save `WooSimpleProductWriter`. Existing linking remains read-only to merchant product content. No schema constraints are weakened.

There is **no approved Production pricing rule in this repository**. `PriceInspector` detects warnings; it is not permission to choose vendor `price`, MSRP, or MAP. The default projection reports `pricing_policy_missing`. Production blocks before the final permit; QA previews and records that warning. `CreationPricingPolicyInterface` is a code-only extension seam for a subsequently approved rule, not a browser setting or vendor-selected mode. Tests supply an explicit fixture policy to exercise the complete Production boundary. The existing remote dry-run entry point is still QA-only; this milestone does not enable a Production fetch entry point.

## Eligibility and initial fields

Only explicitly selected, confirmed, persisted `new_product_candidate` actions qualify. Snapshot hashes, source/environment, vendor identity, normalized string UPC under the existing length/digit policy, freshness HMAC, run/item/action ownership, dispatch and approval generation, current leases, identity/UPC reservations, mapping absence and live Woo UPC absence must hold. Exact matches, manual classifications, any review flag, discontinued and non-purchasable candidates cannot enter creation. Suspicious pricing remains blocked; there is no approved suspicious-price override yet.

Initial fields are title, sanitized option description, regular price from an approved rule, native global unique ID/UPC, simple type, Draft status, and eight private correlation fields. Title is limited to 500 UTF-8 bytes before `sanitize_text_field`; description to 16,000 UTF-8 bytes before `wp_kses_post`. Invalid UTF-8 and oversize content are rejected. Approved regular price must be a positive plain decimal, at most eight integral/four fractional digits. UPC remains a string.

The option description is retained in the immutable approval manifest and snapshot; the upstream full product description is not available in the existing persisted dry-run projection. No short description, weight, dimensions, SKU, sale price, categories, attributes, images, gallery, shipping or tax policy is inferred. WooCommerce may itself initialize default internal properties during its save; tests prove the importer invokes only the allowlisted setters.

## Exact creation algorithm

1. Claim the current logical action/generation/execution lease, then the item. Preflight persisted content and pricing before reservations, so known no-write validation/configuration failures cannot poison a later approval. Then reserve identity and scoped UPC and accept hash-verified snapshot/live freshness.
2. Build the desired projection exclusively from the approved persisted snapshot and code-supplied approved pricing policy. Creation approval binds the environment, field-policy identifier, policy presence, stable pricing ID/version/configuration SHA-256, vendor hash, desired-field hash, sanitized title/description hashes, normalized UPC, resolved regular price and policy failure code. The desired hash also binds simple type, Draft status and correlation-marker version. No browser field values are accepted. QA takes a separate preview transaction and never calls either writer method.
3. Production's final permit locks `run -> action -> item -> catalog identity -> UPC reservation -> mapping rows`; checks ownership, freshness, cancellation and forced live absence; rebuilds current projection/policy binding and compares it with the immutable approval; commits `applying`, `apply_started_at`, durable operation UUID and `create_started` audit before any external call. Missing-to-present pricing, removal, identity/version/configuration changes or changed writable fields require explicit reapproval. The worker never refreshes approval. `approval_projection_changed` blocks execution; unused claims may be released atomically only while durable state proves no permit was issued. A durable recurring recovery action must exist before this transaction begins or creation fails closed.
4. Recheck cancellation, current action generation and both owners/leases immediately before save. A failed recheck leaves a committed intent for reconciliation.
5. Instantiate one `WC_Product_Simple`, set Draft and approved fields, queue all correlation metadata, then call `save()` exactly once. No plugin transaction spans this call.
6. Query exact operation correlation, verify every marker, native UPC, simple type and Draft status. Returned IDs must agree. Exceptions are ambiguous, never proof of no insertion.
7. Finalization locks `run -> action -> item -> catalog identity -> target ownership identity -> UPC reservation -> mapping rows`. Before any settlement mutation, Production requires `applying`/`reconciling`, a nonempty supplied token equal to the locked item's execution token, and an unexpired item lease. The creator passes its original item token; recovery passes its newly claimed token. The same transaction checks the durable operation, source agreement and reservation, reads live UPC ownership again, and creates/adopts only the exact active mapping.
8. Identity binding (`vendor_created`), mapping, vendor-state hash, item `applied`, action completion, one counter increment, correlation/finalization audit and commit form one plugin transaction. Any SQL/audit/commit failure rolls it back. The external Draft remains available for read-only recovery.

## Correlation and the non-atomic save

The existing cryptographically random, unique, persisted item/catalog operation UUID is reused across retries. It is never supplied by browser or vendor data. Private keys are:

- `_ideaxperts_ea_create_operation`
- `_ideaxperts_ea_create_scope`
- `_ideaxperts_ea_create_env`
- `_ideaxperts_ea_create_product`
- `_ideaxperts_ea_create_option`
- `_ideaxperts_ea_create_item`
- `_ideaxperts_ea_create_version` (`3c-v1`)
- `_ideaxperts_ea_create_upc`

Lookup uses a parameterized WordPress posts/postmeta join, exact binary value equality, product post type, DISTINCT IDs and LIMIT 2. WordPress's meta-key index narrows the query, but meta values do not have a universal operation-UUID index; large installations must profile this query. No catalog is loaded into memory for correlation. Post caches are cleared and Woo metadata is force-read before all marker predicates are compared.

[WooCommerce's CPT data store](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php) inserts the post before saving custom metadata. Markers are part of the first CRUD save, but that save is not atomic: a crash may leave an unmarked or partially marked post. Absence of a marker therefore **never** authorizes another create. Such a Draft may require manual identification outside automatic adoption. Hooks can change status/content; incompatible results fail closed without deletion.

## Recovery and retries

Before the committed permit, lease recovery may retry validation normally with the same UUID. After that boundary, `applying` and `reconciling` never return to pending/ready/retry for creation. Bounded reconciliation processes at most 25 expired intents per invocation and performs one correlation lookup per item:

- Worker death after persistence, or loss of the returned ID: one fully matching Draft is adopted and finalized.
- ID returned but plugin settlement fails: roll back plugin state, retain Draft, recover only finalization.
- Failure after identity binding/mapping insertion/item/action/counter updates: transaction rollback retains the original intent; recovery adopts the same external object.
- Response lost after commit: applied item/action fencing makes delivery idempotent.
- No exact object, multiple objects, wrong markers/environment/EA identity/UPC/type/status, or returned ID mismatch: explicit `manual_recovery`, retain reservations, no second save, no automatic deletion.
- Even a writer exception before persistence is handled conservatively because the production API does not prove a no-save outcome.

A crash after `applying` commits but before the actual API call is indistinguishable on disk from a crash during insertion. It therefore also requires reconciliation/manual recovery when no marker exists. This intentionally trades automatic retry availability for duplicate safety.

Recovery does not obtain permission for a new write. It uses the original immutable approval binding and exact operation correlation, independent of the currently configured pricing policy. A policy change after the original save neither abandons nor reprices the Draft. The original desired-field hash is included in bounded finalization audit data. Callback execution owners still fence every new write. Tests model duplicate workers by deterministic callback interleaving; real cross-process concurrency remains an integration requirement.

The existing import manager registers the dedicated Action Scheduler hook `ideaxperts_ea_import_recover_creations`, in the import group, recurring every 60 seconds. Bootstrap `init` installs/repairs it regardless of merchant import enablement or cancellation; Production also verifies the scheduled row before committing its permit, outside custom transactions. It therefore survives creator death without admin activity or another queue call. The background hook performs only creation reconciliation and cancellation settlement, never new creation dispatch. Deactivation removes this plugin-owned recovery schedule; bootstrap restores it after activation.

Each invocation discovers at most 25 expired `applying`/`reconciling` intents, using the existing `(status,lease_expires_at,id)` index shape and expiry ordering. An atomic locked item update claims a fresh token and 300-second recovery lease; unexpired creators/recovery workers cannot be stolen. Creator and recovery finalization both verify the current item token/lease under row locks held through commit. Takeover changes the token and moves the item to `reconciling`, fencing the original creator and every older recovery token before mapping insertion, counter increment or ownership cleanup. An empty token never grants settlement authority. Identity and UPC reservation owner evidence remains unchanged. The recurring action processes remaining batches on later ticks. Normal `ready`, applied and manual items are excluded.

The existing item attempt counter is reset when `applying` commits, then counts autonomous recovery acquisitions. Temporary correlation read failures and finalization rollback defer read-only recovery by 60, 120, 240, 300 and 300 seconds, respectively. At most five lookup/finalization attempts run; the next due acquisition settles `recovery_attempts_exhausted` without another lookup or save. Missing/multiple/invalid correlation and post-save conflicts settle immediately to `manual_recovery`, retaining reservations. SQL unavailability can prevent recording manual settlement; the recurring worker keeps attempting that terminal settlement after database availability returns. No ambiguity path restores `ready` or creation retries.

Post-save live inspection distinguishes proven incompatible UPC ownership (`CatalogInspectionConflict`) from an unavailable inspection. An inspection exception, including failure of the mapping-freshness SELECT inside `LiveCatalogStateProvider::inspect()`, rolls back settlement and returns `inspection_retry`; it never claims `post_save_upc_conflict`. The current unexpired owner records `creation_inspection_read_failed` and a bounded `creation_recovery_deferred` audit event while moving/remaining in `reconciling`. A creator failure waits 60 seconds without counting as an autonomous recovery acquisition; recovery acquisitions then follow the existing five-attempt backoff and sixth-acquisition exhaustion rule. Deferral locks run/item and checks exact token, active state and unexpired lease, so an expired or superseded callback cannot renew ownership. Reservations and operation correlation remain intact, including during cancellation; no additional Woo save is permitted.

If reconciliation reports `correlation_missing` while the original save is still in progress, its eventual Draft remains for manual reconciliation. The original creator has lost ownership and cannot reopen `manual_recovery` or finalize using an empty or stale token. Identity/UPC reservation evidence is retained; no automatic second save or terminal-state override is authorized. Tests pause before persistence, expire the lease, reconcile an empty result, then resume the original save and verify that manual state and zero mappings remain.

## Duplicate guarantees and cancellation

Existing database uniqueness protects EA identity, operation UUID, installation-wide Woo target ownership and mappings. Production UPC reservations serialize this plugin's creation intents. The final live check and immediate post-save ownership read detect external interference, but WordPress/WooCommerce does not provide a universal database-level UPC uniqueness guarantee. An outside actor can race the final check. If another owner appears, retain the created correlated Draft and settle `post_save_upc_conflict` to manual recovery; do not delete either product.

Cancellation before the permit cancels the item and prevents creation. After the permit, a still-current owner checks cancellation immediately before save, but cancellation can arrive after that check. Only the current token holder with an unexpired item lease may finalize a valid correlated Draft while the run is cancelling; then cancellation may settle. Cancellation does not restore authority to a superseded creator or recovery worker. Unknown/conflicting outcomes retain `manual_recovery` and keep cancellation unresolved. Drafts are never automatically deleted. Cancellation after an applied item does not undo its mapping.

## QA and security

The locked database environment determines QA/Production behavior. QA runs eligibility, sanitization, policy validation, freshness and the final permit predicate within its preview transaction; it stores `preview_create_succeeded` with desired-field SHA-256 and pricing warning, no Woo ID, no correlation metadata, no mapping and no global Woo identity key. Preview identity/UPC reservations are released after settlement and can be reused by an independent later preview. Identical immutable snapshots are reused safely rather than failing their existing unique key.

Admin selection and confirmation retain `manage_woocommerce`, action-specific nonces, user-scoped expiring confirmation tokens, server manifest rebuild/hash verification and ID-only input. Creation UI explicitly identifies new Drafts, environment, vendor identity, UPC, intended title, missing price policy and flags. All output is escaped. Callback arguments cannot choose environment, content or price. Audit payloads use `ImportAuditPayload`, store hashes rather than descriptions, and never include credentials or raw exceptions. UUIDs remain private correlation data, not authentication.

## Deterministic validation coverage

`SimpleProductCreationTest` tests production/QA, duplicate and stale callbacks, response replay, ready-state death/retry, committed-intent death with no marker, hard worker death after persistence, exception after persistence before ID, identity/mapping/item/action/counter/audit/commit failure rollback, bad/multiple/missing correlation, returned ID mismatch, cancellation before permit/after permit/after save, external UPC races and mapping/target-identity conflicts. New regressions replace a missing policy before delivery, change price/ID/version/configuration or remove a policy, mutate title/description/UPC snapshots, recheck after preflight, and recover a price-20 Draft after changing the policy to price 25. Background tests invoke the registered scheduled hook without manual/admin reconciliation, exercise live/stale token fencing, temporary read/finalization failure, exhaustion, cancellation, missing/multiple/invalid/conflicting objects, schedule repair/failure and a 26-item batch requiring two ticks. Late pre-permit invalidation also verifies unused reservations are safely released for a fresh approval.

`WooSimpleProductWriterTest` tests the real writer's allowlisted API calls, Draft, initial metadata, exact bounded SQL, metadata omission, QA writer rejection, failed reads and no/multiple results using a standalone Woo API spy. `SimpleProductProjectionTest` tests classifications/flags, vendor identity, UPC, invalid UTF-8, length bounds, malformed prices, and exclusion of weight/future fields. Existing linking, permit, freshness/HMAC, schema uniqueness, audit budgets and Action Scheduler regression suites remain enabled. Sanitizer fakes validate delegation/projection; real WordPress XSS filtering needs integration testing.

## Remaining integration requirements

- **WooCommerce:** test real `WC_Product_Simple`, generated post IDs, Draft/type/UPC APIs across supported versions, first-save metadata, partial metadata failures, hooks, native property defaults, post/product/meta caches, HTML allowlists and external UPC races. Creation-specific inspection always includes native global IDs even when the optional merchant matching setting disables them; a regression covers this configuration.
- **MySQL:** test actual InnoDB finalization atomicity, process concurrency, unique-index conflicts, gap locks/deadlocks, commit outcome ambiguity, snapshot reuse contention and correlation-query performance. Deadlocks/commit failures retain the external object for recovery; the deterministic database fake cannot prove isolation.
- **Action Scheduler:** kill a real worker during save, redeliver delayed/stale generations and verify one product/mapping; verify the durable recurring row survives the crash and its runner invokes bounded recovery without admin activity, including cancellation and temporary database outages. Real execution still requires a functioning Action Scheduler runner/WP-Cron or external cron.

No release ZIP, deployment, commit, merge or push is part of this milestone request.

## Final local validation

- `composer validate --strict`: passed.
- `composer audit --locked --no-interaction`: zero vulnerability advisories.
- Validation checks passed; 97 PHP files linted, 52 coding-standard source files checked, PHPStan reported no errors, and 649 tests with 5,212 assertions passed. The targeted creation suite passes 78 tests and 1,219 assertions; live catalog inspection passes 17 tests and 23 assertions.
- `git diff --check`: passed.
- A subsequent adversarial Bugbot review identified missing policy/projection approval binding and the absence of autonomous recovery dispatch. Both are addressed here with deterministic regressions for the original cases, policy/field drift, live/stale recovery ownership, bounded batches/backoff, rollback and cancellation. No real WooCommerce catalog writes were performed during this work.
- The remaining creator ownership bypass is fenced in repository finalization: creator settlement now carries its existing token and requires a matching unexpired item lease in active intent state under transaction locks. Regressions resume the real creator callback after takeover at post-save and correlation lookup boundaries, including exception cleanup and cancellation. Before authorized recovery completes, mappings/counter remain zero and all database tables remain unchanged by the stale creator. Recovery adopts the original price-20 Draft once despite a price-25 policy change. Added coverage rejects empty/wrong/expired creator tokens, verifies creator-before-takeover ordering, stale recovery cleanup during cancellation, and exception rollback after mapping insertion. The late-save/manual-state bypass is removed; a Draft appearing after terminal missing-correlation settlement requires manual reconciliation.

Branch is `feature/catalog-simple-product-creation`; HEAD remains `35afec1e3d026ec2bf8ccab38c648a9179c86e73`. All changes are uncommitted.
