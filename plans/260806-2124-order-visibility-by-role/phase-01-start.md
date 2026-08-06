---
phase: 1
title: "Scope + controllers"
status: pending
priority: P1
effort: "3-4h"
dependencies: []
---

# Phase 1: Scope + controllers

## Overview

Add a deny-by-default Eloquent visibility scope on `Order` and apply it to every order read/write path that takes an order id or lists orders, including Printify preview/create (visibility **before** shop validation). Also block non-admin `seller_id` reassignment on update.

## Requirements

- Functional: `Order::visibleTo($user)` implements the locked matrix (admin / seller / group_leader with/without group / roleless deny).
- Functional: `OrderController` `index`, `show`, `update`, `destroy` always constrain by that scope.
- Functional: Non-admin `update` cannot change `seller_id` (422 or strip field — prefer explicit 422 validation).
- Functional: `PrintifyOrderController` `preview` / `create` re-resolve `$order` via `visibleTo` **as the first step**, before `validatedShopAndMappings()`.
- Non-functional: Query filters remain AND with visibility.
- Non-functional: Do not claim performance guarantees for leader `whereHas` without measuring.

## Architecture

```php
// app/Models/Order.php (sketch — authoritative after red-team)
public function scopeVisibleTo($query, User $user)
{
    if ($user->hasRole('admin')) {
        return $query;
    }

    if ($user->hasRole('group_leader')) {
        if ($user->sales_group_id === null) {
            // Multi-role leader+seller must still see own rows
            return $query->where('seller_id', $user->id);
        }

        return $query->where(function ($q) use ($user) {
            $q->where('seller_id', $user->id)
                ->orWhereHas('seller', function ($s) use ($user) {
                    $s->where('sales_group_id', $user->sales_group_id);
                });
        });
    }

    if ($user->hasRole('seller')) {
        return $query->where('seller_id', $user->id);
    }

    // Roleless / other roles: deny-by-default
    return $query->whereRaw('0 = 1');
}
```

Controller usage:

- `index`: `Order::query()->visibleTo($request->user())->with([...])` then existing filters.
- `show` / `update` / `destroy`: `Order::visibleTo($request->user())->findOrFail($id)`.
- `update`: after visibility, if `!$user->hasRole('admin')` and request wants to change `seller_id`, reject (do not allow silent ownership transfer).
- Printify: **first line** of `preview`/`create`:  
  `$order = Order::visibleTo($request->user())->findOrFail($order->id);`  
  then existing `validatedShopAndMappings`. Out-of-scope → always 404.

Role precedence: `admin` → `group_leader` (with null-group fallthrough to own id) → `seller` → deny.

<!-- RED-TEAM: deny-by-default; leader null-group own rows; Printify visibility-first; update seller_id guard -->

## Related Code Files

- Modify: `app/Models/Order.php`
- Modify: `app/Http/Controllers/Api/OrderController.php`
- Modify: `app/Http/Controllers/Api/PrintifyOrderController.php`
- Prefer scope-only (no new `OrderPolicy` unless it simplifies)

## Implementation Steps

1. Implement `scopeVisibleTo` exactly per sketch (deny-by-default; leader union/fallthrough).
2. Wire `OrderController` index + findOrFail paths; add non-admin `seller_id` update guard.
3. Wire `PrintifyOrderController` visibility-first re-query.
4. Leave smoke/regression tests to Phase 2 (including existing `OrderShowHttpTest` fixes).

## Todo

- [x] `scopeVisibleTo` on Order (deny-by-default)
- [x] OrderController uses scope for index/show/update/destroy
- [x] Non-admin cannot change `seller_id` on update
- [x] PrintifyOrderController visibility gate **before** shop validation
- [x] Confirm request `seller_id` filter cannot widen scope

## Success Criteria

- [x] Seller listing excludes other sellers' orders
- [x] Leader listing includes group members' orders + own
- [x] Null-group leader still sees own `seller_id` rows
- [x] Roleless actor: empty index / 404 show
- [x] Direct `GET /api/orders/{foreignId}` as seller → 404
- [x] Printify preview on foreign order → 404 even if shop unassigned
- [x] Non-admin PUT changing `seller_id` → 422 (or equivalent reject)

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Leader `whereHas` slow | Soft — revisit after measure; no false index claim |
| Route-model binding loads order before check | Re-query under scope / abort after bind |
| Seller can still DELETE own order (no `orders.delete` in seed) | Residual — documented on plan; out of middleware non-goal |
| CSV null `seller_id` empties seller lists | Document; assign sellers outside this phase |
