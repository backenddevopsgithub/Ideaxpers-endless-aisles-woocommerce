# Vendor order submission and status reconciliation: design

Status: **proposed** (2026-10-30). Nothing in this document is implemented yet. The `ideaxperts_ea_order_submissions` table and the `IdempotencyKey` / `OrderSubmissionRepository` classes exist but are unused, and this design replaces their semantics.

## 1. Non-negotiables

Endless Aisles warned that **every order submitted through the API may be shipped**. Everything below follows from four rules:

1. **At most one vendor order per WooCommerce order, ever.** A retry must never be able to create a second shipment.
2. **An unknown outcome is not a failure.** If we cannot prove the vendor did *not* create the order, we must not send a *different* order. The only automatic retry allowed is one the vendor itself deduplicates.
3. **Durable intent before any network call.** The decision to submit is committed to the database before HTTP. HTTP never runs inside a database transaction.
4. **Off by default, QA first.** Submission has its own kill switch, separate from the integration toggle. Production submission needs an additional explicit confirmation beyond the existing Production safeguard.

## 2. Endless Aisles contract (from docs.endlessaisles.io, read 2026-10-30)

**`PUT /api/orders`** creates an order.

| Field | Rule |
|---|---|
| `shipping.first_name`, `last_name`, `address_line_1`, `city`, `zip` | Required string |
| `shipping.state` | Required string, max length 2 |
| `shipping.address_line_2`, `phone`, `country` | Optional string |
| `customer.email` | Optional email |
| `line_items.*.id` or `line_items.*.upc` | Only one is needed (but both are marked "Required") |
| `line_items.*.quantity` | Required numeric |
| `line_items.*.price` | Required numeric; this is our **retail** unit price |
| `tax_rate`, `shipping_cost`, `discount` | Optional numeric |
| `custom_order_id` | Optional string, **unique per retailer account**; re-sending an existing value "will encounter an error" |

Responses:

- **200:** `{"data":{"id":10030,"status":"Scheduled",...}}`
- **400:** validation failure, with details in the body.
- **500:** "any other error"; details in the body.
- Error bodies look like `{"error":"..."}`.

**`GET /api/orders/{id}`** returns 200 with:

- `status`
- per-line `quantity` and `quantity_shipped`
- `shipments[]` with tracking `url` and `number`
- `last_updated`

It returns 404 if not found. In QA, orders older than a day return 404 because the database is reset daily.

**`GET /api/orders`** filters:

- `query`: matches an email, an order number or a last name. **It does not search by `custom_order_id`.**
- `since`: `m-d-Y`.
- `status`: `open` (PENDING or SCHEDULED) or `closed` (SHIPPED, CANCELED or FAILED).

**Cancel.** The docs' heading says `PUT /orders/{id}/cancel`, but the example uses `GET`. Cancellation is only possible until the order is packed, usually within about 30 minutes during business hours.

**QA behavior.** Inventory is not deducted. Fulfillment is manual and only happens on request.

### The gap that matters

`custom_order_id` gives us vendor-side deduplication, which is exactly what rule 2 needs. But **we cannot look an order up by it**, and we do not know what the duplicate error contains.

After a timeout, re-sending the identical payload is safe: the vendor either creates the order (our first attempt never landed) or rejects it as a duplicate (it did land). In the duplicate case, though, we would know an order exists without knowing its vendor ID. Getting the ID back requires one of the following from Endless Aisles (questions Q1–Q3 in §12):

- the duplicate error returns the existing order ID, or
- `GET /orders` can search by `custom_order_id`, or
- order details echo `custom_order_id` back, so we can match candidates found by email and date.

Until one of these is confirmed, a duplicate response sends the order to `manual_review`. It is never resubmitted under a different ID.

## 3. When an order is submitted

- **Trigger:** the WooCommerce order reaches **Processing**, via `woocommerce_payment_complete` and `woocommerce_order_status_processing`. Both hooks call the same idempotent `record_intent()`, so duplicate callbacks are harmless.
- **Only qualifying lines:** a line is sent only if its product has an active simple-product mapping for the current environment and carries this plugin's creation markers. This is the same rule as inventory sync.
- **Mixed carts:** only the Endless Aisles lines are sent. Local lines are fulfilled by the merchant as usual. An order with no qualifying lines never gets a submission row.
- **Not sent:** orders that are On hold, Pending, Cancelled, Refunded or Failed, and orders created in the admin, until they reach Processing (the trigger decision is noted in §11).

## 4. Data model

Schema migration `3.3.0` extends `ideaxperts_ea_order_submissions`. The table is unused today, so no rows need to be backfilled.

| Column | Purpose |
|---|---|
| `wc_order_id` | **UNIQUE** together with `environment`. One submission per order per environment; this unique key is what makes duplicate callbacks harmless |
| `environment`, `source_scope` | Same scoping as mappings |
| `custom_order_id` varchar(64), **UNIQUE** | Deterministic: `<site key>-<env>-<wc_order_id>`, where the site key is a short hash of `home_url()`. It is stable across retries and safe if several stores share one vendor account |
| `status` | State machine (§5) |
| `owner_token`, `lease_expires_at` | Single-owner claim, using the same compare-and-swap pattern as the import and inventory workers |
| `attempt_count`, `next_attempt_at` | Bounded backoff |
| `payload_hash` char(64) | SHA-256 of the canonical vendor payload frozen at intent time |
| `line_snapshot` JSON | Option ID, UPC, quantity and unit price per line. **No address or other personal data** |
| `ea_order_id` | Set only from a vendor 200 response, or by a recorded admin decision |
| `vendor_status`, `vendor_last_updated`, `status_synced_at` | Reconciliation results |
| `shipped_json`, `tracking_json` | Shipped quantity per line, and tracking numbers and URLs |
| `last_error_code`, `last_error` | Bounded, redacted text. Vendor error bodies are stored redacted |
| `submitted_at`, `accepted_at`, `created_at`, `updated_at` | Audit trail |

Every transition also adds an order note and an event row, reusing the `import_events` pattern, so support can follow the full history.

The existing `idempotency_key` column (a hash of the order ID plus line item IDs) is retired. It changes whenever the order is edited, which is the opposite of what an idempotency key must do.

## 5. State machine

```
            record_intent (unique wc_order_id)
                       │
                       ▼
   ┌────────────── pending ──────────────┐
   │  (kill switch off / gate fails →     │
   │   stays pending, no HTTP)            │
   │                                      │
   │  claim (CAS lease, attempt++)        │
   ▼                                      │
submitting ──200 + data.id──────────────► accepted ──reconcile──► scheduled / partially_shipped
   │                                              │                  shipped / cancelled / failed
   ├──400 validation (not duplicate)─► rejected ──► alert, manual
   │
   ├──duplicate custom_order_id──────► manual_review (vendor has it; ID unknown) ──► alert
   │
   ├──timeout / transport / 5xx / malformed 200 / lease expired mid-call
   │                 │
   │                 ▼
   │              unknown ──backoff──► submitting (SAME custom_order_id, SAME payload_hash)
   │                 │
   │                 └──attempts exhausted──► manual_review ──► alert
   │
   └──pre-send check fails (order refunded/cancelled, mapping gone,
      payload changed since intent) ─► blocked ──► alert, manual
```

Rules:

- **`submitting` is committed before the request is sent.** A worker that crashes mid-request leaves `submitting` with an expired lease. Recovery treats that as `unknown`, never as "not sent".
- **Retries from `unknown` re-send the identical payload under the same `custom_order_id`.** The rebuilt payload must hash to `payload_hash`; if the order was edited, the item goes to `blocked`. The vendor's uniqueness check makes these retries safe.
- **Only a 200 with a valid `data.id` sets `ea_order_id` and `accepted`.**
- **A 500 counts as `unknown`, not `rejected`,** because the vendor may have created the order before failing.
- **Admin actions are limited to:**
  - "Record vendor order #" (from `manual_review` or `unknown`)
  - "Do not send" (from `pending`, `blocked` or `rejected`)
  - "Retry identical payload" (from `rejected` once the cause is fixed, or `blocked` once an edit is reverted)

  There is **no** "resubmit as new order". Each action requires a nonce and `manage_woocommerce`, and is audited.

## 6. Payload

- **`shipping`** comes from the WooCommerce shipping address, falling back to billing. The state must be 2 letters; anything else is `blocked`. The country is US-only until the vendor confirms otherwise (Q6).
- **`line_items`:** `id` = the Endless Aisles option ID from the mapping. UPC is omitted unless the vendor requires it (Q4). `quantity` = line quantity. `price` = line subtotal ÷ quantity, as a two-decimal string (the discount and tax treatment is a merchant decision, §11).
- **`customer.email` is not sent by default.** Sending it is a merchant decision (§11): it is personal data, and we do not know whether the vendor contacts customers.
- **`shipping_cost`, `tax_rate`, `discount`** are omitted by default. They only affect the vendor's reporting of our retail order, not what we pay.
- **The canonical JSON is frozen at intent time.** Only its hash and the line snapshot without personal data are stored. Each attempt rebuilds the payload from the order and verifies the hash.

## 7. Gates checked immediately before every HTTP attempt

1. Order submission is enabled (new setting, default **off**).
2. The environment is QA. For Production, the existing `production_confirmed` safeguard **and** a new, separate `production_orders_confirmed` must both be set. Approvals never carry over between environments.
3. The order is still Processing (or Completed), and is not refunded or cancelled.
4. Every line in the snapshot still maps to the same option ID.
5. The rebuilt payload hash equals `payload_hash`.
6. The lease is still owned by this worker (fencing token).

Any failure leaves the row unsent, with a reason, and raises an alert.

## 8. Scheduling

- `record_intent()` enqueues a unique async action per order: `ideaxperts_ea_submit_order` with the args `[submission_id]`.
- A recurring sweeper runs every 5 minutes. It picks up `pending`, `unknown` past `next_attempt_at`, and `submitting` with an expired lease.
- **Backoff:** 1, 5, 15, 60 and 240 minutes, then `manual_review`.
- One HTTP request per claim, with a 30-second timeout. No retry loop inside a request: `RequestRetry` is for idempotent GETs only and is **not** used for PUT.
- The order with the oldest `next_attempt_at` goes first. At most 10 orders per sweep run.

## 9. Status reconciliation

- **Every 30 minutes:** `GET /api/orders/{ea_order_id}` for `accepted`, `scheduled` and `partially_shipped` rows, oldest `status_synced_at` first, bounded per run.
- **Mapping of vendor responses:**
  - `Scheduled` / `Pending` → `scheduled`
  - every line has `quantity_shipped` ≥ `quantity` and status `Shipped` → `shipped`
  - some lines shipped → `partially_shipped`
  - `Canceled` → `cancelled`
  - `Failed` → `failed`
- **Tracking:** each new shipment number adds an order note and an `_ideaxperts_ea_tracking` meta entry, without duplicates.
- **WooCommerce order status is not changed automatically.** This is a merchant decision (§11). The proposed default: mark the order Completed only when it has no local lines and is fully shipped; otherwise add a note only. `cancelled` and `failed` raise an alert and a note, with **no automatic refund**.
- **A 404** in QA counts as `vendor_record_expired` (expected after the daily reset). In Production it raises an alert.

## 10. Cancellation (phase 2)

Cancelling or refunding a WooCommerce order after the vendor accepted it currently raises an alert and adds an order note: "Vendor order #X may still ship; cancel with Endless Aisles within ~30 minutes." Automatic cancellation waits for Q5.

## 11. Merchant decisions needed

1. **Which order states trigger submission.** Proposed: Processing only.
2. **Line `price`.** Pre-discount or post-discount, and with or without tax.
3. **Whether to send `customer.email`** and the phone number to Endless Aisles.
4. **Whether a shipped vendor order may automatically complete the WooCommerce order** (only if it has no local lines).
5. **Who handles `manual_review` alerts**, and how quickly. This matters because a cancellation is only possible within about 30 minutes.
6. **Whether non-US addresses** are blocked or handled manually.

## 12. Questions for Endless Aisles

These should go with the 2-week end-to-end testing notice.

- **Q1.** When `PUT /orders` repeats a `custom_order_id`, what is the exact status code and body? Does it include the existing order ID?
- **Q2.** Can `GET /orders` filter by `custom_order_id`? If not, does `GET /orders/{id}` return `custom_order_id`?
- **Q3.** Is `custom_order_id` uniqueness enforced atomically, so that two simultaneous PUTs with the same value cannot both create an order?
- **Q4.** For line items, is `id` (the option ID) alone sufficient, or is `upc` also required? The docs mark both as "Required".
- **Q5.** Cancellation: is it `PUT` or `GET` `/api/orders/{id}/cancel`, and what response means "too late to cancel"?
- **Q6.** Which countries ship? Is `shipping.country` required for non-US addresses?
- **Q7.** On a 500, can the order have been created? Should a 500 be treated as "possibly created"?
- **Q8.** Are QA orders ever shipped automatically? (We assume not; fulfillment is manual.)

## 13. Test plan

Unit and integration tests use a fake vendor that keeps its own order store and enforces `custom_order_id` uniqueness. The scenarios, mapped to handoff Tests 9 and 10:

| Scenario | Expected |
|---|---|
| Normal success | 1 vendor order; `accepted` with its ID |
| Duplicate payment callbacks or status hooks | 1 row, 1 vendor order |
| Two workers claim at the same time | 1 HTTP attempt |
| Vendor creates the order, then the response times out | `unknown`, then the retry gets a duplicate → `manual_review`; vendor still has **1** order |
| Timeout where the vendor did *not* create it | Retry creates exactly 1 |
| Worker crashes after `submitting` is committed | Lease expiry → `unknown` → same safe retry path |
| 400 validation | `rejected`, alert, no retry |
| 500 | `unknown` (not `rejected`) |
| Order edited between intent and send | `blocked` |
| Refunded before send | `blocked`, never sent |
| Kill switch off, or Production unconfirmed | No HTTP request at all |
| Mixed cart | Only mapped lines in the payload |
| Reconciliation: scheduled, partial, shipped, cancelled, QA 404 | Correct state, notes, tracking without duplicates |

Then, on staging against QA during the coordinated window: one single-item order, one mixed cart, one deliberate timeout simulation (proxy delay), and a manual cancellation.

## 14. Build order

| Phase | Scope | Blocked by |
|---|---|---|
| A | Migration 3.3.0, repository, intent recording, gates, payload builder and hash, admin list with read-only states | Nothing |
| B | Submission worker with the full state machine against the fake vendor; sweeper; alerts | Nothing (Q1–Q3 only change how `manual_review` resolves) |
| C | Status reconciliation and tracking notes | Nothing |
| D | Admin resolution actions | Merchant decision 5 |
| E | Real QA submission | Endless Aisles answers to Q1–Q4, plus the coordinated test window |
| F | Cancellation | Q5 |
