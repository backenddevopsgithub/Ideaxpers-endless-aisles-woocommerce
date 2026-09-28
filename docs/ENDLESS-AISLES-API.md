# Endless Aisles API contract notes

Authoritative documentation: <https://docs.endlessaisles.io/>  
Reviewed: 2026-09-25

This is a concise implementation record, not a replacement for the official documentation.

## Environments and authentication

- QA: `https://app-qa.endlessaisles.io/`
- Production: `https://app.endlessaisles.io/`
- Authentication header: `X-EA-REQUEST-TOKEN: {token}`

Development belongs in QA. Production access requires a separate stored Production token and an explicit production-safety confirmation; selecting Production does not authorize a request.

## Documented endpoints

- `GET /api/products`
- `GET /api/products/{id}`
- `GET /api/products/bysize/{id}`
- `GET /api/products/{productId}/options/{optionId}`
- `GET /api/stock`
- `GET /api/stock/byUpc`
- `GET /api/orders`
- `GET /api/orders/{id}`
- `PUT /api/orders`

Order creation is registered for future work but is not implemented or called in Milestone 1. Cancellation is intentionally not registered because its method is ambiguous (see below).

## Product and size fields

The documented product representation contains `id`, `title`, `brand`, `description`, `ingredients`, `analysis`, `image_url`, `additional_image_urls`, `metadata`, and `sizes`.

Documented metadata keys are `species`, `category`, `life_stage`, `product_group`, `main_ingredient`, `breed_size`, `special_condition`, `subcategory`, `type`, and `country_of_origin`.

Documented size fields are `id`, `upc`, `description`, `price`, `wholesale`, `purchasability`, `discontinued`, `minimum_advertised_price`, `wholesale_source`, `msrp`, `shipping_weight_lbs`, and `phillips_item_number`. UPCs are identifiers and are preserved as strings, including leading zeroes.

## Pagination

Product listing accepts `page` and `per_page`. Product-list responses document `current_page`, `data`, `from`, `last_page`, `next_page_url`, `path`, `per_page`, `prev_page_url`, `to`, and `total`. The API examples return a relative `next_page_url`; the plugin extracts only `page` and `per_page` from a relative value and rebuilds the known product route. Absolute next-page URLs are rejected. Pagination state is bounded to 1,000 pages, 1,000 records per request, and 100,000 records overall and rejects repeated pages to prevent loops.

The Milestone 1 connection test performs only `GET /api/products?page=1&per_page=1`, validates the documented list envelope, and discards the response.

All API responses use WordPress's transport-level response limit and are rejected before JSON decoding when their downloaded body reaches the configured ceiling or a valid `Content-Length` exceeds it. The default ceiling is 5 MiB, which leaves substantial room for the documented default catalog page while preventing unbounded response buffering. Code-level configuration is capped at 20 MiB.

## Synchronization and QA behavior

- Product metadata: initial pull followed by an update every 24 hours, per the official recommendation. The importer and product schedule are deferred beyond Milestone 1.
- Inventory: every 30 minutes, per the approved plugin requirement. Milestone 1 schedules the action but exits safely without importing, including when credentials are absent.
- The QA database refreshes daily. Older QA order details may therefore return `404` after order data is cleared.
- QA inventory is not automatically deducted merely because a test order is placed; fulfillment must be performed manually by Endless Aisles for that test behavior.
- `custom_order_id`, when supplied, is unique within the retailer account; reusing it produces an error.

## Milestone 2 dry-run usage

Catalog dry runs use only `GET /api/products` against QA with explicit `page` and `per_page=10` on every request, including page 1. Pages are processed sequentially. A bounded outbox reconciler performs scheduling and cancellation only; it never makes Endless Aisles requests. The implementation limits page number, per-page size, total records, response bytes, redirects, timeouts, attempts, Retry-After delay (30 seconds), and pagination loops. Relative pagination references are reduced to `page` and `per_page` and rebuilt on the known endpoint. Numeric JSON UPC values are rejected because leading zeros may already be lost.

HTTP 429, 5xx, and transport timeouts receive at most three attempts with bounded backoff. Authentication/authorization responses (401/403), validation failures, malformed JSON, oversized responses, and unexpected structures are not retried. Empty `data` lists are valid. Logs contain event names, run/page identities, status, and bounded error classifications—not tokens, request URLs, or bodies.

Only documented product and size fields are retained. `price`, `wholesale`, `minimum_advertised_price`, and `msrp` are inspected for reporting only. No price is calculated or written to WooCommerce.

## Known documentation ambiguities

- Cancellation: the section heading says `PUT /orders/{id}/cancel`, while its HTTP Request example says `GET /api/orders/{id}/cancel`. Neither is implemented pending confirmation from Endless Aisles.
- The general pagination table shows a default `page` value of 10, while the product and order endpoint parameter tables show a default page of 1.
- The product search example uses `/api/products/search?term=...`, but the documented HTTP request is `GET /api/products` and `term` is listed as its query parameter.
- Order line-item validation labels both `upc` and `id` as required, while the adjacent note says only one of the two identifiers is required.
- The product-option example prefixes its UPC string with an apostrophe, unlike the size examples. The plugin treats UPC as an opaque string and does not infer whether that apostrophe is data or a presentation artifact.
- No rate limits or authoritative maximum `per_page` value are documented. Plugin-side pagination limits are safety controls, not claims about server limits.
