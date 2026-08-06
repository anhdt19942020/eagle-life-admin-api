---
phase: 2
title: "Tests and dual-layer API docs"
status: completed
priority: P1
effort: "1-2h"
dependencies: [1]
---

# Phase 2: Tests and dual-layer API docs

## Overview

Prove seller cannot delete; keep admin/leader soft-delete coverage; document dual-layer auth and FE menu permission map in `API_DOCS.md`.

## Requirements

- Functional: Feature tests cover seller 403 (row not trashed), admin and/or group_leader successful soft-delete
- Docs: dual-layer section + §4.4 requires `orders.delete`; remove residual claiming seller can DELETE
- Non-functional: no FE code changes in this repo

## Architecture

| Surface | Gate |
|---------|------|
| Orders list/show/update | Role + `scopeVisibleTo` (auth only on route) |
| DELETE order | `orders.delete` + visibility |
| Users / roles / sales-groups / printify accounts | Spatie permissions (already) |
| FE menu show/hide | Same permission strings from `/me` → `roles[].permissions[]` |

### FE menu map (document only)

| Menu / action | Show when |
|---------------|-----------|
| Orders | Role `seller` / `group_leader` / `admin` |
| Delete order (UI) | `orders.delete` |
| Users | `users.view` |
| Sales groups | `sales-groups.view` |
| Printify accounts | `printify.accounts.view` |
| Printify catalog/shops | `printify.catalog.view` |
| Import | `orders.import` |
| Printify create on order | `printify.order.create` |

## Related Code Files

- Modify: `tests/Feature/OrderSoftDeleteTest.php` — switch destroy success cases from seller → admin (or leader)
- Modify: `tests/Feature/OrderAuthorizationTest.php` — add seller destroy 403 + optional leader OK
- Modify: `API_DOCS.md` — dual-layer + §4.4 + clear Visibility residual about seller DELETE
- Optional: any other Feature test that `deleteJson('/api/orders/...')` as seller

## Implementation Steps

1. Grep tests for `deleteJson('/api/orders` and fix actors lacking `orders.delete`.
2. In `OrderSoftDeleteTest::test_destroy_soft_deletes_and_sets_deleted_by`, act as `admin` (or `group_leader` with visibility); assert soft-delete + `deleted_by` still.
3. Add `test_seller_cannot_delete_order` (seed roles): seller owns order → DELETE → 403; `assertDatabaseHas` / not soft-deleted.
4. Optionally assert group_leader can delete in-scope order (seed already grants permission).
5. Update `API_DOCS.md`:
   - Near §4 intro / Visibility: dual-layer paragraph (Orders = role+scope; admin menus = permission; delete = `orders.delete`).
   - §4.4: require permission `orders.delete` (admin + group_leader seeded; seller not).
   - Remove/replace residual line about seller still being able to DELETE.
6. Run Feature subset: `OrderAuthorizationTest`, `OrderSoftDeleteTest`, and any failing destroy callers.

## Success Criteria

- [x] Seller DELETE → 403; order not soft-deleted
- [x] Admin (and/or leader) soft-delete tests green
- [x] API_DOCS dual-layer + §4.4 accurate; seller-delete residual gone
- [x] Targeted Feature tests pass

## Risk Assessment

- Soft-delete plan status still `pending` while code exists — docs/tests here must stay compatible with soft-delete destroy body; do not revert SoftDeletes.
- FE may lag behind API — document authoritative 403 behavior.
