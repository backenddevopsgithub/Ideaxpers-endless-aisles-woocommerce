# Controlled QA creation

This milestone changes the real simple-product creation path to QA only. It does not deploy, import products, or enable any pricing configuration.

## Existing architecture and changes

Milestones 3C/3D already provide a canonical approval manifest, immutable vendor snapshots and pricing/field bindings, signed live catalog freshness, vendor identity and store UPC reservations, a transactional final permit, durable intent, one WooCommerce save with eight correlation markers, and read-only recovery. Mapping insertion, item application, counters and audit events settle transactionally. These mechanisms are reused.

The earlier gap was that QA approvals were previews while Production held the real creation path. New approvals carry `controlled_qa` in the manifest, snapshot and binding. Old QA previews cannot become real creation permissions. The real permit and writer reject Production. QA creation reserves UPCs in the installation-wide `woocommerce_catalog` namespace. Live inspection reads QA mappings for creation and checks vendor correlation even if a mapping was deleted and the UPC changed.

## Admin workflow

1. Complete a QA catalog dry run. The catalog approval panel defaults to the latest completed QA run and filters new candidates. A read-only `creation_dry_run_id` query parameter opens a particular completed QA run; `candidate_page` paginates 20 candidates.
2. Review vendor product/option IDs, UPC, title, description, Retail/Wholesale/MAP, resolved selling price, policy method/source/currency/precision/freshness/reference, MAP decision, proposed Publish/Draft status, purchasability, discontinued status, classification, flags, environment, observation/completion timestamps and mapping state.
3. Select 1 to 5 candidates on one page. Review the immutable selected field projection and pricing configuration.
4. Explicitly confirm to approve and queue execution. Viewing pages never queues or saves products. POST handlers require `manage_woocommerce` and action-specific nonces. Confirmation tokens are user-specific and expire after 15 minutes.
5. Inspect the latest run and each selected item's outcome and WooCommerce ID. `applied` with a product ID means created/already linked, `blocked` has a failure code, `stale_snapshot` requires reapproval, and `manual_recovery` requires review. Duplicate delivery leaves an applied item unchanged.

## Eligibility and binding

Only completed QA sources with exact `endless-aisles:qa` scope and a completion timestamp within 24 hours qualify for controlled approval. Active plugin environment must be QA. Candidate observations must also meet the explicitly configured pricing freshness period. Candidates require `new_product_candidate`, nonempty vendor IDs, a normalized valid UPC, purchasable=1, discontinued=0, no review flags, no current mapping, no live target, no UPC owner, and no SKU owner when SKU matching applies. Empty selection, sixth candidate, duplicate selected vendor identity or UPC, invalid title/content/price and unresolved pricing are rejected. No automatic selection exists.

Approval binds source run/generation/completion timestamp, QA scope, item IDs, exact vendor snapshot, current local/mapping fingerprint, matching settings, creation mode, sanitized title/description hashes, UPC, Retail/Wholesale/MAP, exact regular price, MAP decision, proposed status, field policy and pricing identity/version/configuration hash. Prepare, review and confirm rebuild and compare the projection. The final transactional permit repeats current pricing, MAP/status projection, and live catalog checks; creation start repeats QA settings and source age checks. Drift requires fresh explicit approval. Recovery adopts the original approved fields, price, and status without recalculating under changed settings.

## Pricing configuration

Pricing configuration is explicitly saved in the plugin settings and remains disabled until the merchant checks the enable control and saves it. Missing configuration blocks with `pricing_policy_missing`; there is no constant or implicit fallback. The staging values are `rule=direct_source`, `source_field=retail_price`, `currency=USD`, `decimal_places=2`, `max_age_seconds=86400`, `map_rule=draft_below_map`, and `zero_map_rule=no_restriction`, with a required nonsecret approval reference and no bounds. Currency/decimals must match WooCommerce. Explicit `percentage_markup` and `fixed_markup` structures are available for a future decision, require an explicit markup value, and participate in the policy configuration hash; neither is selected automatically. Missing/malformed MAP remains blocking. MAP zero is no restriction. Retail below positive MAP retains Retail as the selling price and proposes Draft; no MAP uplift occurs. Preview configuration is allowlisted and omits unknown credential fields.

## Written fields and recovery

One new simple product receives sanitized title, safe HTML option description, approved regular price, native global unique ID/UPC, and the exact approved status: Publish for a clean eligible item or Draft for Retail below positive MAP. Eight metadata markers record operation, scope, environment, vendor product/option, import item, correlation version and UPC. An active QA mapping links vendor IDs to the WooCommerce ID. No SKU, inventory, images, brand, dimensions, shipping, categories or attributes are added.

Before committed intent, retry is safe. After committed intent, recovery never saves again: one exact correlated product with the originally approved title/description/price/status/UPC and metadata is adopted. Missing/multiple/changed objects require manual recovery. Read failures retry with bounded backoff; exhausted attempts require review. Exact tokens and leases fence stale creators and recovery owners. Transaction rollback preserves the external object for adoption and permits only one mapping and successful counter. Subsequent comparison reads the QA mapping and classifies the option as already_linked.

## Remaining gate

Merchant save/approval of the explicit staging pricing configuration remains required before any real controlled QA test. This implementation does not perform the staging test or deployment. Production creation remains rejected. The historical 3C/3D documents describe the prior Production/QA-preview behavior; this document supersedes that behavior for controlled creation. Images, richer metadata, inventory, checkout validation, orders, daily scans, and snapshot reuse remain future work.
