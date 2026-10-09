# Catalog dry-run worker and local snapshot design

## Worker implementation

The previous worker committed one API page and created one durable action intent
per page. Scheduler latency was therefore paid approximately 210 times for the
reported catalog, even when every API request succeeded.

The catalog worker now retains its starting-page intent and execution token while
processing sequential pages. It stops after 10 successful pages, after the
injectable monotonic clock reaches 25 seconds, or on vendor completion. The clock
is checked after each durable page commit and includes API, classification, and
database time. Existing API retries remain unchanged. An in-flight page or retry
sequence can exceed the budget; it must finish or fail before the worker exits.

Each page uses its own transaction, without any API request inside it. The run
and action rows are locked and checked for claim token, claim generation, run
status, action identity, execution token, lease, and exact next-page ordering.
Items, classification and review aggregates, product counters, cursor, heartbeat,
diagnostics, and execution lease commit together. Intermediate pages leave the
intent running. Successfully checkpointed pages are never fetched again on
recovery.

At a budget boundary, a separate fenced transaction validates the next cursor,
creates one uniquely indexed continuation intent, records its page and stop
reason, and completes the current intent. Immediate reconciliation dispatches
the continuation. The final vendor page instead completes the run and intent
atomically, with no continuation.

If a process dies after a page commit but before continuation creation, execution
lease recovery redispatches the original immutable starting-page intent. Only a
previously started catalog intent may claim an advanced run cursor. Its new
execution token fences out the old worker, and the manager starts at
`current_api_page + 1`. If continuation creation commits before a crash,
reconciliation dispatches the existing intent. Duplicate callbacks and repeated
reconciliation cannot create another continuation.

Cancellation and ownership are checked between pages; page writes and chunk
finalization recheck them under locks. API failure on page 5 keeps pages 1–4
durable and retains `catalog:5` for the existing failure/resume flow. Product
counters advance with the page, while classification/review counters are rebuilt
from persisted items. An old execution cannot fail a replacement execution.

Same-second audit: intermediate intent lease updates can legitimately affect zero
rows. The transaction already locks and authenticates that row, so a non-false
database result succeeds. Timestamp refresh methods retain their full ownership
and lease confirmation predicates when zero rows change. Fresh token acquisition
and actual status transitions still require a changed row where appropriate.

Diagnostics use a nullable JSON field on the run; schema version is 3.2.0.
Existing progress presentation is preserved, with readable latest worker page
count, continuation page, stop reason, and local snapshot reuse status. Tokens
are never included. A blank stop reason means an execution has checkpointed a
page but has not completed its chunk normally.

For 210 successful pages, the page limit permits 21 catalog executions. Time
limits, API failures, or crash recovery can increase that count. There is no
hardcoded catalog size or guarantee of 21–30 jobs for slow API responses.

## Local UPC Discovery reuse: design only

Reuse remains disabled. Every new dry run performs a fresh local scan, and
diagnostics report reuse as false. Timestamp-only reuse cannot prove freshness.

A safe follow-up implementation would require:

1. A durable, monotonic catalog change generation covering products and
   variations, creation/deletion, trash/restore, relevant status/type/parent
   changes, global unique ID, SKU, configured UPC metadata, and all supported
   import/write paths. Invalidation must precede visible mutations; a post-save
   hook alone leaves a race. Direct SQL writes or extensions that bypass the
   tracked paths invalidate this proof. Where those writes cannot be excluded,
   require a consistent authoritative content fingerprint or perform a fresh
   scan. Counts and maximum modification timestamps alone are insufficient.
2. A canonical fingerprint containing that generation, product and variation
   counts, global unique ID setting, sorted UPC metadata keys, SKU-to-UPC matching
   setting, scanner/schema/normalization versions, and scan scope. Capture it
   before and after discovery. Mark the snapshot reusable only if both agree,
   the run completed successfully, and all scanned records are present with an
   integrity manifest. Catalog changes during scanning force a fresh scan.
3. A bounded freshness policy in addition to the fingerprint. Missing versions,
   unknown writer coverage, mismatched settings or generations, failed integrity
   checks, and partially purged snapshots all fall back to scanning.
4. Under run ownership, validate the snapshot fingerprint again, then copy local
   identifiers and local scan counters into the new run. Do not copy vendor
   items, classifications, mapping results, claim tokens, or action intents.
   Classifications must use current mappings and the new run's vendor scope.
   Pin the source against retention while copying; commit the destination's
   complete snapshot and phase transition atomically. For large copies, keep a
   private incomplete destination until all batches and manifests validate.
5. Revalidate the catalog generation around copy/publication and fail closed on
   any race, cancellation, loss of ownership, retention conflict, or database
   failure. Persist reuse status and source snapshot identity only after a fully
   validated copy. Release retention pins on completion and crash recovery.

These catalog-wide invalidation and snapshot publication changes are deliberately
outside the vendor throughput fix.
