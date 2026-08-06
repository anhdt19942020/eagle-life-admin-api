---
phase: 3
title: "Account-scoped Printify integration"
status: completed
priority: P1
effort: "10-13h"
dependencies: [1, 2]
---

# Phase 3: Account-scoped Printify integration

## Overview

Remove the runtime dependency on one global Printify token. Make client, shop sync, scheduled commands, catalog access, and order preview/create resolve the account from the selected/assigned shop, with server-side assignment enforcement for seller and group leader.

<!-- Updated: Validation Session 1 - rebaseline: factory/client/account-scoped sync+order-create already in tree; cook finishes callers, policy, order resolver, commands, locks, and integration tests -->
**Rebaseline (Validation Session 1):** Do **not** re-implement `PrintifyClientFactory` or rewrite already-account-scoped sync/order-create service cores. Remaining work is wiring + enforcement + tests.

## Context links

- `app/Services/Printify/PrintifyClient.php:10-49`
- `app/Services/Printify/PrintifySyncService.php:14-205`
- `app/Services/Printify/PrintifyOrderPreviewService.php:11-196`
- `app/Services/Printify/PrintifyOrderCreateService.php:11-88`
- `app/Http/Controllers/Api/PrintifyOrderController.php:14-70`
- `app/Http/Controllers/Api/PrintifyShopController.php:14-132`
- `app/Console/Commands/SyncPrintifyShops.php`
- `app/Console/Commands/SyncPrintifyOrders.php`
- `app/Console/Commands/SyncPrintifyProducts.php`
- `app/Console/Commands/SyncPrintifyUploads.php`
- `routes/console.php:11-17`
- [Printify API Reference](https://developers.printify.com/API-Doc-RREdits.html/1000)

## Key insights

- `PrintifyClient` is injected into multiple services and currently reads config at request time. A mutable process-global account/token would leak credentials across requests; use an immutable account-scoped client instance derived from `PrintifyAccount`.
- **Injection mechanism (decided, not left to the implementer):** `PrintifySyncService.php:14` and `PrintifyOrderCreateService.php:15` both hold `private readonly PrintifyClient $client` from the container. A per-account client cannot come from constructor injection. Replace that dependency with a new `PrintifyClientFactory` that takes the `HttpFactory` and returns `PrintifyClient::forAccount($account)`; each service method resolves its account and asks the factory. `PrintifyClient` keeps no static token cache. This changes the constructor signature of every Printify service and every test that builds one from the container — it is the largest mechanical cost in this phase and is why the effort estimate is 10-13h, not 8-10h.
- Printify enforces rate limits **per account**, not per process. With N accounts the scheduled sync fan-out multiplies. Iterate accounts sequentially (never concurrently) inside a schedule window, and treat an account whose sync exceeds the window as skipped-until-next-run rather than overlapping.
- Shop sync currently deactivates every missing remote ID and uses one global lock. Both operations must include account identity.
- Existing order services already receive a local `PrintifyShop`; the smallest safe change is to resolve `shop->account` at the boundary and use its scoped client, rather than threading account IDs through payload construction.
- Printify shop IDs are globally unique, so existing product/order local foreign keys can remain based on the local shop row.

## Requirements

- Functional: all outbound calls use the API key belonging to the shop's account.
- Functional: shop sync accepts an account, only upserts/deactivates that account's shops, and uses account-specific locks.
- Functional: scheduled sync commands iterate active accounts/shops and skip inactive accounts without making remote calls.
- Functional: admin may choose any active-account shop; seller/leader always use their assignment.
- Functional: missing assignment, missing account, inactive account, inactive/closed/not-ready shop, and spoofed shop selection fail before a remote order request.
- Compatibility: existing remote paths/payloads and readiness gates remain unchanged after the account is resolved.
- Security: no fallback to `PRINTIFY_TOKEN` in normal web/scheduled runtime after bootstrap; no token in logs or errors.

## Architecture

```text
PrintifyClientFactory::for(account)
  └─ immutable token + shared base URL/retry settings
```
<!-- Updated: Validation Session 1 - diagram matches factory already in tree; was PrintifyClient::forAccount -->

```text
sync(account)
  └─ lock printify:sync:account:{id}
  └─ shop lock printify:sync:shop:{accountId}:{remoteId}
  └─ query/update shops where printify_account_id = account.id
  └─ cross-account remote ID → abort/error (no silent skip)

order request
  ├─ admin: validate request shop_id → shop
  └─ seller/leader: user.printifyShop → shop
       └─ spoofed shop_id ≠ assignment → 422
       └─ require shop.account.is_active
       └─ scoped client posts to /shops/{remote_id}/orders.json
```
The non-admin resolver must not use a request-provided `shop_id`. <!-- Updated: Validation Session 1 - dual-side FE+BE; spoof → 422 -->
**Validation Session 1:** Always resolve seller/leader from `auth()->user()->printifyShop`. If the request includes a `shop_id` that differs from the assignment, return `422` with a stable code (do not silently ignore and do not use the spoofed shop). Phase 4 must remove seller/leader shop picker/`shop_id` payloads so the happy path never sends that field.

The existing `POST /printify/shops/sync` endpoint requires `account_id` and its `printify.sync` permission becomes **admin-only** (see the plan-level contract decision). The controller loads that active account and passes it to `PrintifySyncService::syncShops`. The account management page calls this same endpoint for account-1 or another selected account.

**Ownership guard for the remaining non-admin Printify routes.** `routes/api.php:46-52` currently exposes six routes whose only gate is a permission string, and `PrintifyShopController::index` applies no user scoping at all. Under multiple accounts a `group_leader` could otherwise sync, open, close, re-SKU, or enumerate shops belonging to another account. Every non-admin request on these routes must resolve against `auth()->user()->printify_shop_id`:

| Route | Admin | Non-admin (group_leader) |
|---|---|---|
| `GET /printify/shops` | all shops, optional `account_id` filter | filtered to the caller's assigned shop only |
| `GET /printify/products` | all | filtered to the caller's assigned shop only |
| `POST /printify/shops/sync` | allowed, requires `account_id` | **403** — permission no longer granted |
| `PATCH /printify/shops/{shop}` | any shop | `403` unless `{shop}` is the caller's assignment |
| `POST /printify/shops/{shop}/confirm-manual-approval` | any shop | `403` unless `{shop}` is the caller's assignment |
| `POST /printify/shops/{shop}/open` and `/close` | any shop | `403` unless `{shop}` is the caller's assignment |

Implement the ownership check once — a single policy or a small shared guard used by every `{shop}`-bound action — not repeated inline per method.

## File inventory

| Action | File | Test impact |
|---|---|---|
| Create | `app/Services/Printify/PrintifyClientFactory.php` | Builds an immutable per-account client; the only place a key is decrypted |
| Modify | `app/Services/Printify/PrintifyClient.php` | Account-scoped authorization header; preserve retry behavior |
| Modify | `app/Services/Printify/PrintifySyncService.php` | Constructor takes the factory, not a client; account argument, scoped query/locks for all sync methods |
| Modify | `app/Services/Printify/PrintifyOrderPreviewService.php` | Constructor takes the factory; resolve `shop->account` and guard active state before readiness work |
| Modify | `app/Services/Printify/PrintifyOrderCreateService.php` | Constructor takes the factory; use the shop account's client for POST |
| Modify | `app/Http/Controllers/Api/PrintifyOrderController.php` | Role-aware shop resolver and stable preflight errors |
| Create | `app/Policies/PrintifyShopPolicy.php` | Single ownership guard: admin any shop, non-admin only `user.printify_shop_id` |
| Modify | `app/Http/Controllers/Api/PrintifyShopController.php` | Account-scoped sync/filter contract; non-admin index filtered to the assigned shop; policy on every `{shop}` action |
| Modify | `app/Http/Controllers/Api/PrintifyProductController.php` | Reject shops without active account; non-admin listing filtered to the assigned shop |
| Modify | `app/Console/Commands/SyncPrintifyShops.php` | Iterate active accounts |
| Modify | `app/Console/Commands/SyncPrintifyOrders.php` | Resolve account through each shop |
| Modify | `app/Console/Commands/SyncPrintifyProducts.php` | Resolve account through each shop |
| Modify | `app/Console/Commands/SyncPrintifyUploads.php` | Iterate active accounts |
| Modify | `app/Console/Commands/EnsurePrintifyShopDefaultSku.php` | Pass the shop's account when seeding a product |
| Modify | `routes/console.php` | Keep schedules account-aware and non-overlapping |
| Modify | `tests/Feature/PrintifyClientTest.php` | Multiple-account bearer-token assertions |
| Modify | `tests/Feature/PrintifySyncTest.php` | Per-account scope and lock assertions |
| Modify | `tests/Feature/PrintifyShopSyncApiTest.php` | Account parameter and isolation |
| Modify | `tests/Feature/PrintifyOrderPreviewTest.php` | Assignment/account preflight |
| Modify | `tests/Feature/PrintifyOrderCreateTest.php` | Assigned shop, admin selection, spoof prevention |

## Implementation Steps

1. **Verify (already done) then skip re-implementation:** Confirm `PrintifyClientFactory::for(PrintifyAccount)` and that Sync/OrderCreate depend on the factory, not a constructor-injected `PrintifyClient`. Quarantine normal runtime `PRINTIFY_TOKEN` (bootstrap-only). Do not rebuild this core unless a gap is found.
2. **Fail loudly on cross-account mismatch:** Change shop sync so a remote shop ID already owned by another account **throws/aborts** (no silent `reject`/skip). Scope upserts, deactivation, and locks by account id. Shop lock key: `printify:sync:shop:{accountId}:{remoteId}`.
3. Update product/order/upload sync **callers** (controllers + scheduled commands) to pass each shop's account. Skip inactive accounts; iterate accounts **sequentially**; keep `withoutOverlapping`; log skipped-account counts when a run does not finish within its window.
4. Shop listing/sync endpoints: **`account_id` required** on `POST /printify/shops/sync` (no default to account 1). Apply `PrintifyShopPolicy` to every `{shop}` action; filter non-admin index/product listing to the caller's assignment; eager-load `account`. Do not expose API keys.
5. Shared order shop resolver: admin validates request shop; seller/leader loads assignment; absent/inactive → stable `422` `data.code`; **spoofed `shop_id` ≠ assignment → `422`** (never use the spoofed shop).
6. Preview/create verify account active before readiness/remote work. Keep idempotency keys and Printify URL payloads unchanged.
7. **Own integration test rewrite in this phase** (Validation Session 1): update `PrintifyClientTest`, `PrintifySyncTest`, `PrintifyShopSyncApiTest`, `PrintifyOrderPreviewTest`, `PrintifyOrderCreateTest`, plus any test still resolving `app(PrintifyClient::class)` or calling account-less `syncShops()` / setting global token for HTTP. Cover two-account bearer isolation, cross-account sync abort, account-keyed locks, spoof `422`, zero HTTP on preflight errors.
8. Grep for remaining runtime `config('services.printify.token')` HTTP paths; leave bootstrap + `.env.example` rollout window only.

## Test scenario matrix

| Scenario | Expected result | Verification owner |
|---|---|---|
| Account A client request | Bearer token A only | Phase 3 client test |
| Account B client request after A | Bearer token B; no shared token state | Phase 3 client test |
| Sync account A missing shop | Only A's missing shop deactivates | Phase 3 sync test |
| Sync account A with existing shop owned by B | Sync aborts/errors; B's shop unchanged; never silent skip | Phase 3 sync test |
| Parallel account locks | A and B do not block each other; same account does; shop lock includes account id | Phase 3 sync test |
| Seller sends another shop ID | `422`; assigned shop never replaced by spoof | Phase 3 order test |
| Leader opens/closes/re-SKUs another account's shop | `403`; target shop unchanged; no HTTP sent | Phase 3 authorization test |
| Leader calls shop sync | `403` — permission is admin-only | Phase 3 authorization test |
| Leader lists shops/products | Only the assigned shop is returned | Phase 3 authorization test |
| Admin lists 1000 shops with account metadata | Bounded query count; `account` eager-loaded | Phase 3 shop list test |
| Seller has no assignment | `422 printify_shop_assignment_required`; no HTTP sent | Phase 3 order test |
| Assigned account inactive | `422/409` stable account-inactive error; no HTTP sent | Phase 3 order test |
| Admin selects shop | Selected active account shop is used | Phase 3 order test |
| Scheduled sync | Active accounts processed; inactive accounts skipped | Phase 3 command test |

## Function/interface checklist

- [x] No service holds a constructor-injected `PrintifyClient`; every client comes from `PrintifyClientFactory` with an explicit account.
- [x] Every `PrintifyClient` caller has an explicit account source.
- [x] Scheduled account iteration is sequential and bounded by the schedule window.
- [x] `syncShops` has no account-less production path.
- [x] Every sync lock includes account identity where state can cross accounts.
- [x] The order resolver is shared by preview and create.
- [x] Non-admin input cannot select a shop outside `User::printifyShop` — on order routes **and** on every shop/product/readiness route.
- [x] The ownership rule exists in exactly one place (policy/guard), not copied per controller method.
- [x] No normal runtime path reads `config('services.printify.token')` for outbound HTTP.

## Dependency map

- Phase 1 supplies encrypted credentials and account/shop FKs.
- Phase 2 supplies active-account lookup, user assignment, seller permission, and safe user context.
- Phase 3 unblocks the frontend: `/me`, shop list, sync, and order endpoints have their final contracts.
- Phase 3 owns integration/regression tests for account-scoped client, sync, orders, and scheduled callers (Validation Session 1). Phase 5 retains account CRUD/bootstrap/assignment suites, docs, and rollout.

## Success Criteria

- [x] Two accounts can sync/create through different bearer tokens in the same process without cross-use.
- [x] Shop sync aborts on cross-account remote ID ownership; cannot deactivate or move another account's shop.
- [x] Seller/leader preview and create use only the assigned shop; spoofed `shop_id` → `422`.
- [x] Missing/inactive assignment fails before Printify HTTP.
- [x] Admin shop selection remains functional; sync requires `account_id`.
- [x] Schedules no longer depend on one global token or global account lock; shop locks include account id.
- [x] Updated Printify integration feature tests pass under the multi-account contract.

## Risk Assessment

- A missed scheduled command can still use a legacy global client. Mitigation: contract checklist plus grep all `PrintifyClient` instantiation/callers and run command tests.
- Client construction can accidentally decrypt the key multiple times or retain it longer than needed. Mitigation: immutable request-scoped client, no static token cache, no logging.
- Old FE may still send `shop_id` until Phase 4. Mitigation: BE returns `422` on spoof (never uses foreign shop); Phase 4 removes seller/leader picker and must ship after API. <!-- Updated: Validation Session 1 -->
