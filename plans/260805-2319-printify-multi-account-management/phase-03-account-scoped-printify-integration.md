---
phase: 3
title: "Account-scoped Printify integration"
status: pending
priority: P1
effort: "10-13h"
dependencies: [1, 2]
---

# Phase 3: Account-scoped Printify integration

## Overview

Remove the runtime dependency on one global Printify token. Make client, shop sync, scheduled commands, catalog access, and order preview/create resolve the account from the selected/assigned shop, with server-side assignment enforcement for seller and group leader.

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
PrintifyClient::forAccount(account)
  └─ immutable token + shared base URL/retry settings

sync(account)
  └─ lock printify:sync:account:{id}
  └─ query/update shops where printify_account_id = account.id

order request
  ├─ admin: validate request shop_id → shop
  └─ seller/leader: user.printifyShop → shop
       └─ require shop.account.is_active
       └─ scoped client posts to /shops/{remote_id}/orders.json
```

The non-admin resolver must not use a request-provided `shop_id`. During rolling deployment it may accept an old field for compatibility, but it still resolves the authenticated user's assignment and cannot be redirected by that field.

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

1. Add `PrintifyClientFactory::for(PrintifyAccount $account): PrintifyClient`, which decrypts the key only inside client setup and retains base URL/timeout/retry configuration. Change `PrintifySyncService`, `PrintifyOrderPreviewService`, and `PrintifyOrderCreateService` constructors to depend on the factory instead of `PrintifyClient`, and update every container-resolved construction site in app and test code. Remove the normal request fallback to the environment token after bootstrap.
2. Change `PrintifySyncService::syncShops` to accept an account. Scope remote IDs, upserts, deactivation, and the account lock by account id; reject an impossible local remote-ID/account mismatch rather than silently reassigning it.
3. Update product/order/upload sync methods and scheduled commands to resolve each shop's account and use its client. Skip inactive accounts; report counts without credential details. Iterate accounts **sequentially** so per-account Printify rate limits are not multiplied by concurrency, keep `withoutOverlapping` on the schedule, and log a skipped-account count when a run does not finish the list within its window.
4. Update shop listing/sync endpoints to accept account context and preserve existing readiness/open/default-SKU actions. Apply `PrintifyShopPolicy` to every `{shop}`-bound action and filter the non-admin index/product listing to the caller's assignment. Eager-load `account` on shop queries — `PrintifyShopController.php:26` uses a bare `PrintifyShop::query()` and allows `per_page` up to 1000, so account metadata on the resource would otherwise cause up to 1000 extra queries. Do not expose API keys through shop resources.
5. Implement one shared order shop resolver in the controller/service boundary: admin validates request shop; seller/leader loads `auth()->user()->printifyShop`; any absent/inactive relation returns a stable `422` code before preview/create.
6. Make preview and create verify account active before readiness/remote work. Keep local idempotency keys and Printify URL payloads unchanged.
7. Add explicit tests for two accounts with different fake bearer tokens, cross-account sync deactivation, account-specific locks, assignment spoofing, and zero HTTP calls on preflight errors.
8. Remove or quarantine normal runtime use of `PRINTIFY_TOKEN`; retain it only for the one-time bootstrap command and documented rollout window.

## Test scenario matrix

| Scenario | Expected result | Verification owner |
|---|---|---|
| Account A client request | Bearer token A only | Phase 5 client test |
| Account B client request after A | Bearer token B; no shared token state | Phase 5 client test |
| Sync account A missing shop | Only A's missing shop deactivates | Phase 5 sync test |
| Sync account A with existing shop owned by B | Fail safely; never reassign silently | Phase 5 sync test |
| Parallel account locks | A and B do not block each other; same account does | Phase 5 sync test |
| Seller sends another shop ID | Assigned shop is used or request is rejected; other shop never used | Phase 5 order test |
| Leader opens/closes/re-SKUs another account's shop | `403`; target shop unchanged; no HTTP sent | Phase 5 authorization test |
| Leader calls shop sync | `403` — permission is admin-only | Phase 5 authorization test |
| Leader lists shops/products | Only the assigned shop is returned | Phase 5 authorization test |
| Admin lists 1000 shops with account metadata | Bounded query count; `account` eager-loaded | Phase 5 shop list test |
| Seller has no assignment | `422 printify_shop_assignment_required`; no HTTP sent | Phase 5 order test |
| Assigned account inactive | `422/409` stable account-inactive error; no HTTP sent | Phase 5 order test |
| Admin selects shop | Selected active account shop is used | Phase 5 order test |
| Scheduled sync | Active accounts processed; inactive accounts skipped | Phase 5 command test |

## Function/interface checklist

- [ ] No service holds a constructor-injected `PrintifyClient`; every client comes from `PrintifyClientFactory` with an explicit account.
- [ ] Every `PrintifyClient` caller has an explicit account source.
- [ ] Scheduled account iteration is sequential and bounded by the schedule window.
- [ ] `syncShops` has no account-less production path.
- [ ] Every sync lock includes account identity where state can cross accounts.
- [ ] The order resolver is shared by preview and create.
- [ ] Non-admin input cannot select a shop outside `User::printifyShop` — on order routes **and** on every shop/product/readiness route.
- [ ] The ownership rule exists in exactly one place (policy/guard), not copied per controller method.
- [ ] No normal runtime path reads `config('services.printify.token')` for outbound HTTP.

## Dependency map

- Phase 1 supplies encrypted credentials and account/shop FKs.
- Phase 2 supplies active-account lookup, user assignment, seller permission, and safe user context.
- Phase 3 unblocks the frontend: `/me`, shop list, sync, and order endpoints have their final contracts.
- Phase 5 owns regression tests for all changed callers and scheduled commands.

## Success Criteria

- [ ] Two accounts can sync/create through different bearer tokens in the same process without cross-use.
- [ ] Shop sync cannot deactivate or move another account's shop.
- [ ] Seller/leader preview and create use the assigned shop regardless of request payload.
- [ ] Missing/inactive assignment fails before Printify HTTP.
- [ ] Admin shop selection remains functional and account-scoped.
- [ ] Schedules no longer depend on one global token or global account lock.

## Risk Assessment

- A missed scheduled command can still use a legacy global client. Mitigation: contract checklist plus grep all `PrintifyClient` instantiation/callers and run command tests.
- Client construction can accidentally decrypt the key multiple times or retain it longer than needed. Mitigation: immutable request-scoped client, no static token cache, no logging.
- Rolling deployment may send old `shop_id` fields. Mitigation: backend resolver ignores non-admin selection and accepts old payload shape until FE rollout completes.
