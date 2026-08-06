# 2026-08-07 — Drop orders-sync gate for Printify draft create

## Context
Sellers could not create Printify draft orders while a shop’s `orders_sync_state` was still pending/incomplete/syncing. The shop picker and create path both treated `orders_sync_incomplete` as a hard readiness blocker.

## Root cause
`PrintifyShop::readinessIssues()` emitted `orders_sync_incomplete` whenever `orders_sync_state !== 'complete'`. Downstream consumers (`isReadyForCreation()`, shop resource `ready_for_creation`, preview/create preflight) all derive from that list, so one gate blocked the whole create flow.

## Fix
Removed `orders_sync_incomplete` from readiness. Sync fields remain on the API for monitoring. Other gates unchanged (active shop/account, open, default SKU, manual approval, order conflicts).

## Verification
`PrintifySyncTest` + `PrintifyShopDefaultSkuSyncApiTest` + `PrintifyOrderCreateTest` + `PrintifyOrderPreviewTest` — 46 passed. Added seller create coverage with `orders_sync_state=pending`.

## Accepted risk
Local idempotency on `(printify_shop_id, ebay_order_number)` cannot see remote Printify orders that have not been synced yet. Product accepted this tradeoff to unblock draft creation; remote `external_id` pre-check is a possible follow-up.
