---
phase: 2
title: "Tests and API docs"
status: pending
priority: P1
effort: "2-3h"
dependencies: [1]
---

# Phase 2: Tests and API docs

## Overview

Lock the visibility matrix with Feature tests, **fix existing order HTTP tests** that will break under the new scope, and document the contract (including null-`seller_id` CSV reality and residual risks) in `API_DOCS.md`.

## Requirements

- Functional: New suite proves admin / seller / leader matrix for `GET /orders`, `GET /orders/{id}`, update (incl. `seller_id` reject), destroy or show IDOR, and Printify `{order}` path with **404**.
- Functional: Seller cannot widen via `?seller_id=` of another user.
- Functional: Roleless user → empty; null-`seller_id` order → admin only.
- Functional: Existing `OrderShowHttpTest` (and any similar) updated so actors are admin **or** orders have appropriate `seller_id`.
- Non-functional: Sanctum + `RolePermissionSeeder` patterns like `UserRoleGroupValidationTest`.
- Docs: visibility rules; 404 = not found **or** not visible; CSV null-`seller_id` = admin-only until assigned; residual import write / delete-permission gaps called out briefly.

## Architecture

Suggested test file: `tests/Feature/OrderVisibilityTest.php`

| Case | Actor | Expect |
|------|-------|--------|
| List own only | seller A | Sees order(seller_id=A); not B |
| List group | leader G (group set) | Sees A+B in group G; not C other group |
| List own when leader null group | leader+seller, `sales_group_id` null | Sees own `seller_id` only |
| List all | admin | Sees A+B+C + null-seller order |
| Show foreign | seller A → order B | 404 |
| Filter widen | seller A `?seller_id=B` | Empty / no B rows |
| Roleless | permission-only / no role | Empty list; show → 404 |
| Null seller_id | seller / leader | Hidden; admin sees |
| Update seller_id | seller A tries `seller_id=B` | 422 (or reject) |
| Printify IDOR | seller A **with assigned shop** preview order B | **404** (visibility before shop checks) |

Seed minimal users + orders inline (`Order::create`). For Printify IDOR, give seller A a valid `printify_shop_id` so failure mode is visibility 404, not shop 422.

Must-update existing tests:

- `tests/Feature/OrderShowHttpTest.php` — roleless + null `seller_id` currently expects 200; switch actor to `admin` (or assign `seller_id` + seller role).

## Related Code Files

- Create: `tests/Feature/OrderVisibilityTest.php`
- Modify: `tests/Feature/OrderShowHttpTest.php`
- Modify: `API_DOCS.md` (Orders list/detail + Printify order note + null-seller / residuals)
- Grep for other Feature tests hitting `GET /api/orders` as non-admin without `seller_id` and fix if needed

## Implementation Steps

1. Fix `OrderShowHttpTest` (and any siblings found by grep) for the new scope.
2. Add `OrderVisibilityTest` covering the matrix (incl. roleless, null-seller, update guard, Printify 404 with shop assigned).
3. Update `API_DOCS.md` §4 Orders: visibility table; filter ∩ scope; 404 semantics; CSV null-`seller_id` admin-only; brief residual notes (import write / delete permission).
4. Run `php artisan test --filter=OrderVisibility|OrderShowHttp` and a Printify preview smoke if touched.

## Todo

- [x] Fix `OrderShowHttpTest` under visibility scope
- [x] `OrderVisibilityTest` matrix green (incl. roleless, null-seller, update, Printify 404)
- [x] `API_DOCS.md` visibility + data/residual notes
- [x] Confirm list envelope unchanged for FE

## Success Criteria

- [x] `php artisan test --filter=OrderVisibility` passes
- [x] `php artisan test --filter=OrderShowHttp` passes
- [x] Docs match implemented rules
- [x] Printify IDOR asserts **404**, not 422

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Flaky role seed | `RolePermissionSeeder` in `setUp` |
| Printify IDOR flaky 422 | Seed assigned shop; visibility-first already in Phase 1 |
| Broader suite breakage | Grep `/api/orders` Feature tests before done |
