# Controlled QA creation

This milestone changes the real simple-product creation path to QA only. It does not deploy, import products, or enable any pricing configuration.

## Existing architecture and changes

Milestones 3C/3D already provide a canonical approval manifest, immutable vendor snapshots and pricing/field bindings, signed live catalog freshness, vendor identity and store UPC reservations, a transactional final permit, durable intent, one WooCommerce save with eight correlation markers, and read-only recovery. Mapping insertion, item application, counters and audit events settle transactionally. These mechanisms are reused.

The earlier gap was that QA approvals were previews while Production held the real creation path. New approvals carry `controlled_qa` in the manifest, snapshot and binding. Old QA previews cannot become real creation permissions. The real permit and writer reject Production. QA creation reserves UPCs in the installation-wide `woocommerce_catalog` namespace. Live inspection reads QA mappings for creation and checks vendor correlation even if a mapping was deleted and the UPC changed.

## Admin workflow

1. Complete a QA catalog dry run. The catalog approval panel defaults to the latest completed QA run and filters new candidates. A read-only `creation_dry_run_id` query parameter opens a particular completed QA run; `candidate_page` paginates 20 candidates.
2. Review vendor product/option IDs, UPC, title, description, Retail/Wholesale/MAP, selling price, policy ID/version/reference, purchasability, discontinued status, classification, flags, Draft status, environment, observation/completion timestamps and mapping state.
3. Select 1 to 5 candidates on one page. Review the immutable selected field projection and pricing configuration.
4. Explicitly confirm to approve and queue execution. Viewing pages never queues or saves products. POST handlers require `manage_woocommerce` and action-specific nonces. Confirmation tokens are user-specific and expire after 15 minutes.
5. Inspect the latest run and each selected item's outcome and WooCommerce ID. `applied` with a product ID means created/already linked, `blocked` has a failure code, `stale_snapshot` requires reapproval, and `manual_recovery` requires review. Duplicate delivery leaves an applied item unchanged.

## Eligibility and binding

Only completed QA sources with exact `endless-aisles:qa` scope and a completion timestamp within 24 hours qualify for controlled approval. Active plugin environment must be QA. Candidate observations must also meet the explicitly configured pricing freshness period. Candidates require `new_product_candidate`, nonempty vendor IDs, a normalized valid UPC, purchasable=1, discontinued=0, no review flags, no current mapping, no live target, no UPC owner, and no SKU owner when SKU matching applies. Empty selection, sixth candidate, duplicate selected vendor identity or UPC, invalid title/content/price and unresolved pricing are rejected. No automatic selection exists.

Approval binds source run/generation/completion timestamp, QA scope, item IDs, exact vendor snapshot, current local/mapping fingerprint, matching settings, creation mode, sanitized title/description hashes, UPC, exact regular price, Draft field policy and pricing identity/version/configuration hash. Prepare, review and confirm rebuild and compare the projection. The final transactional permit repeats current pricing and live catalog checks; creation start repeats QA settings and source age checks. Drift requires fresh explicit approval. Recovery adopts the original approved fields and price without recalculating from changed pricing settings.

## Pricing configuration

No approved configuration is included. Missing configuration blocks with `pricing_policy_missing`. The existing `IDEAXPERTS_EA_CREATION_PRICING` server-side constant supplies an array only after a merchant decision. Required fields are `approval_reference` (nonsecret, at most 128 bytes), `rule=direct_source`, `source_field` (retail_price, wholesale_price or map_price), `currency`, `decimal_places`, `max_age_seconds`, `map_rule` (block_below_map or ignore_map), and `zero_map_rule` (no_restriction or block). Optional minimum_price and maximum_price are explicit decimal bounds. Currency/decimals must match WooCommerce. There is no implicit markup, margin, MAP floor or zero-MAP exemption. With block_below_map, missing/invalid MAP blocks; a selling price below positive MAP blocks rather than increasing the price. Zero MAP follows the explicit rule. Preview configuration allowlists business fields and omits unknown credential fields.

## Written fields and recovery

One new simple Draft receives sanitized title, safe HTML option description, approved regular price and native global unique ID/UPC. Eight metadata markers record operation, scope, environment, vendor product/option, import item, correlation version and UPC. An active QA mapping links vendor IDs to the WooCommerce ID. No SKU, inventory, images, brand, dimensions, shipping, categories or attributes are added. No existing new-product status setting was found: Draft stays fixed; Publish configuration is future work.

Before committed intent, retry is safe. After committed intent, recovery never saves again: one exact correlated Draft with original title/description/price/UPC and metadata is adopted. Missing/multiple/changed objects require manual recovery. Read failures retry with bounded backoff; exhausted attempts require review. Exact tokens and leases fence stale creators and recovery owners. Transaction rollback preserves the external object for adoption and permits only one mapping and successful counter. Subsequent comparison reads the QA mapping and classifies the option as already_linked.

## Remaining gate

Merchant approval of explicit pricing and MAP/zero-MAP decisions remains required before any real controlled QA test. Staging verification and deployment require a separate authorized task. The historical 3C/3D documents describe the prior Production/QA-preview behavior; this document supersedes that behavior for controlled creation. Images, richer metadata, inventory, checkout validation, orders, daily scans, snapshot reuse and Publish remain future work.
