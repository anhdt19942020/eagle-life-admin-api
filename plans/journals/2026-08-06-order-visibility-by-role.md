---
title: Order visibility by role
date: 2026-08-06
summary: Closed order IDOR with scopeVisibleTo row-level scope; CSV orders admin-only until seller_id backfilled
---

# Order visibility by role

**Date**: 2026-08-06 21:24
**Severity**: High
**Component**: Order API (`OrderController`, `PrintifyOrderController`, `Order` model)
**Status**: Resolved

## What Happened

`GET /api/orders` and every order read/write path (`show`, `update`, `destroy`) plus the Printify `preview`/`create` endpoints had zero row-level authorization. Any authenticated user with `orders.view` could list, read, edit, or delete **any** eBay order regardless of who it belonged to ? a straight-up IDOR across the whole orders surface. Earlier plans only ever gated the *permission* to hit the endpoint, never *which rows* a non-admin should see. This plan (`260806-2124-order-visibility-by-role`) closed that gap in two phases.

## The Brutal Truth

This is the kind of hole that looks fine in every demo because the person testing it is always an admin. A seller or group leader account could have paged through `/api/orders` and pulled every buyer email, address, and eBay order number in the system ? and nobody would have noticed until an actual non-admin account got created and someone asked "wait, why can I see this?" Cheap to fix, expensive to have shipped.

## Technical Details

Added `Order::scopeVisibleTo(Builder $query, User $user)`:
- `admin` ? no constraint.
- `group_leader` with `sales_group_id` set ? `seller_id = auth.id` **OR** `whereHas('seller', sales_group_id = auth.sales_group_id)`.
- `group_leader` with `sales_group_id === null` ? falls through to own rows only (a leader/seller dual-role account must never get blinded to zero rows, but also must never see everything).
- `seller` ? `seller_id = auth.id`.
- anything else (roleless / permission-only accounts) ? `whereRaw('0 = 1')`, deny-by-default.

Wired into `OrderController::index/show/update/destroy` via `Order::query()->visibleTo($user)->findOrFail($id)`, and ? critically ? into `PrintifyOrderController::preview`/`create` as the **first line**, before `validatedShopAndMappings()`. Ordering matters here: if shop validation ran first, an out-of-scope order would leak a `422 printify_shop_assignment_required` instead of a clean `404`, which tells an attacker the order *exists* even though they can't touch it. Also added an update guard: non-admin users who try to change `seller_id` on an order now get a `422 ValidationException` ("B?n kh?ng ???c thay ??i seller c?a ??n h?ng") instead of silently reassigning ownership.

`OrderVisibilityTest` (10 new cases, 27 assertions) and the pre-existing `OrderShowHttpTest` (2 cases, 13 assertions) both pass green ? `php artisan test --filter=OrderVisibility` / `--filter=OrderShowHttp`.

## What We Tried

No dead ends this round ? the plan's red-team review (10 findings, 8 accepted/2 residual) front-loaded the hard calls (deny-by-default vs. fail-open, leader null-group fallthrough, Printify ordering) before implementation started, so Phase 1/2 execution was straight-line. The one thing that did require a second look: `OrderShowHttpTest` originally exercised a roleless actor against a null-`seller_id` order and expected `200` ? that test was **wrong** under the new contract and had to be fixed to use an admin actor, not the scope relaxed to match the test.

## Root Cause Analysis

Nobody ever wrote a `seller_id`-scoping query because, until now, only admins used the orders endpoints in practice. The permission model (`orders.view` via Spatie) checks *can you call this route* but was never paired with *which rows does the query return*, and that distinction got lost. Classic "permission ? visibility" gap.

## Lessons Learned

- A permission check on a route tells you nothing about row-level scope ? always ask "which query, not just which route."
- CSV import reality check: `OrderImportService::persistCsvOrder` **never sets `seller_id`**. Under the new rule, every historical CSV-imported order is now **admin-only** until someone manually assigns a seller. This was flagged Critical in red-team specifically because an earlier draft of the plan assumed import already handled it ? false. Document data realities before writing scope rules, not after.
- When closing an IDOR that has two failure branches (404 vs. business-rule error), check which one runs first. A 422 leaks existence; a 404 doesn't. Order the checks so the discovery-safe one always wins.

## Residual Risks (deliberately out of scope, not forgotten)

- **Import write-side IDOR**: `OrderImportService` can still write a foreign `seller_code` cross-group on import (a `group_leader` with `orders.import` could, in theory, attribute an order to a seller outside their own group). Red-teamed, explicitly rejected for this plan, tracked as a separate residual ? needs its own plan.
- **Missing delete permission**: routes never got `permission:orders.*` middleware attached (non-goal here), so a `seller` can still `DELETE` their *own* order via the visibility-scoped `destroy` even though the seed doesn't grant `orders.delete`. Scope-based row filtering is not the same as CRUD-level permission gating ? this plan intentionally didn't conflate the two, but it means `destroy` is only safe *between* sellers, not *within* one seller's own judgment.

## Next Steps

- Someone (ops) needs to backfill/assign `seller_id` on existing CSV orders or accept they stay admin-only indefinitely ? no owner assigned yet.
- File a follow-up plan for the import cross-group `seller_code` write path and for attaching `permission:orders.*` to the CRUD routes.
- `API_DOCS.md` ?4 now documents the visibility table, 404 semantics, CSV null-seller reality, and both residuals in Vietnamese to match the rest of the doc ? read it before touching `OrderController` again.

AgentWiki publish skipped (unavailable in this environment) ? journal kept local at `plans/journals/2026-08-06-order-visibility-by-role.md`.

> Historical work record — not durable authority. Prefer docs/specs/ADRs for current decisions.
