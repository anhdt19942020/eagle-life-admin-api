# Fix Log

One greppable line per bug fix: `- <date> · <slug> · <tier> · root cause: <one sentence> · <file:line> · <commit-sha>`

- 2026-08-26 · printify-order-create-409-reconcile · external-integration · root cause: local dedup guard checks only local printify_orders table, so an order Printify already holds (but never recorded locally) re-POSTs and Printify's 409 surfaced as a fatal red error instead of a graceful already-exists outcome · app/Services/Printify/PrintifyOrderCreateService.php:49 · c4d8782
