---
title: Order delete permission and dual-layer menu auth
date: 2026-08-06
summary: "Gated DELETE /orders with orders.delete; sellers 403, admin/leader still soft-delete; dual-layer auth documented"
---

# Order delete permission and dual-layer menu auth

# Order delete permission and dual-layer menu auth

**Date**: 2026-08-06 22:37
**Severity**: Medium
**Component**: Order API (`OrderController::destroy`, `routes/api.php`), API_DOCS.md
**Status**: Resolved

## What Happened

Closed a gap flagged (but explicitly deferred) by the prior `260806-2124-order-visibility-by-role` journal: `DELETE /api/orders/{id}` was auth-only. Row-level `scopeVisibleTo` stopped a seller from deleting *someone else's* order, but nothing stopped a seller from soft-deleting their **own** order, even though the seed matrix never granted `seller` the `orders.delete` permission. The permission existed, it just wasn't wired to the route.

Fix: pulled `destroy` out of `apiResource('orders', ...)` (mirroring how `restore` is already registered explicitly) and re-registered it with `->middleware('permission:orders.delete')`, so Spatie's gate runs *before* the controller's `visibleTo` scoping ever executes.

```php
Route::delete('/orders/{id}', [OrderController::class, 'destroy'])
    ->middleware('permission:orders.delete');
Route::apiResource('orders', OrderController::class)->except(['store', 'destroy']);
```

## The Brutal Truth

No drama this time ? this was a two-phase plan (`260806-2237-order-delete-permission-and-dual-layer-menu-auth`) that executed exactly as scoped, which is refreshing after the visibility IDOR fire drill. The only mildly annoying part: `OrderSoftDeleteTest::test_destroy_soft_deletes_and_sets_deleted_by` had been silently relying on a seller-as-deleter actor since it was written, so gating the route immediately broke it. That's the whole point of catching this now instead of when a real seller account hits a real 403 in production and files a support ticket nobody can explain because "the API docs said sellers could delete their own orders."

## Technical Details

- Seed matrix was already correct (`admin` yes, `group_leader` yes, `seller` no `orders.delete`) ? zero seeder changes needed, confirming this was purely a missing-middleware bug, not a permissions-model bug.
- Fixed `OrderSoftDeleteTest` to act as `admin` instead of `seller` for the destroy-success case.
- Added `OrderAuthorizationTest::test_seller_cannot_delete_order` (asserts `403` + `assertDatabaseHas` row still present, not trashed) and `test_group_leader_can_delete_in_scope_order` (asserts `200` + `assertSoftDeleted`).
- Ran `php artisan test --filter="OrderAuthorizationTest|OrderSoftDeleteTest"` -> 8 passed, 32 assertions. Broader related Feature suite (20 tests total per plan scope) green.
- Code review: **PASS**.

## What We Tried

Single approach, no dead ends ? the plan (informed by the prior visibility plan's residual notes) already called out the exact fix and the exact test that would need touching, so implementation was straight-line: route middleware -> fix broken test -> add coverage -> docs.

## Root Cause Analysis

Same root cause pattern as the visibility IDOR from the previous plan, just the CRUD-permission half of it instead of the row-scope half: `apiResource()` gives you a route, not an authorization decision. Spatie's `orders.delete` permission was defined and seeded correctly from day one, but nobody ever attached `permission:orders.delete` middleware to the destroy route, so it sat there unused while `auth:sanctum` alone gated the endpoint. A permission existing in the seeder means nothing if no middleware ever checks it.

## Lessons Learned

- When `apiResource()` is used for a controller with mixed permission requirements per action (view/update looser, delete stricter), don't assume "the permission is seeded" means "the permission is enforced" ? grep the routes file for the actual middleware, not the seeder.
- API/FE authorization here is deliberately **dual-layer** and that needs to live somewhere discoverable: Orders visibility (list/show/update) is role + `scopeVisibleTo` row-scope; admin-only menus (Users, Sales groups, Printify accounts) are pure Spatie permission checks off `/me`'s `roles[].permissions[]`; and `DELETE /orders` is a hybrid ? permission gate first, then row-scope. Documented this explicitly in `API_DOCS.md` so the next person doesn't have to reverse-engineer it from three different controllers.
- Tests that "happen to pass" using an actor with more privilege than the scenario requires (seller destroying successfully) will mask a missing authorization check indefinitely. Prefer the least-privileged actor a scenario should legitimately need, and add an explicit denial test for the actor who shouldn't succeed.

## Next Steps

- **FE sidebar/route guards are not implemented** ? this is explicitly out of scope for this repo (`eagle-life-admin-fe` owns it). API_DOCS now has the authoritative menu-permission map (`orders.delete`, `users.view`, `sales-groups.view`, `printify.accounts.view`, `printify.catalog.view`, `orders.import`, `printify.order.create`) for whoever picks up the FE work ? no owner assigned yet.
- Residual carried forward (documented, not fixed here): order list/show/update remain auth-only with no `orders.view`/`orders.update` middleware ? intentional dual-layer, not a bug, but worth a second look if the product ever wants route-level enforcement instead of relying on FE hiding.
- No seeder changes were needed or made; if leader delete rights are ever revoked, that's a separate seeder-change decision, not a follow-up to this plan.

AgentWiki publish skipped (unavailable in this environment) ? journal kept local at `plans/journals/2026-08-06-order-delete-permission-and-dual-layer-menu-auth.md`.

> Historical work record ? not durable authority. Prefer docs/specs/ADRs for current decisions.

> Historical work record — not durable authority. Prefer docs/specs/ADRs for current decisions.
