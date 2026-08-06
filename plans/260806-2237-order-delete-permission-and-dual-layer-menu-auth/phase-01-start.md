---
phase: 1
title: "Gate destroy with orders.delete"
status: completed
priority: P1
effort: "1h"
dependencies: []
---

# Phase 1: Gate destroy with orders.delete

## Overview

Attach Spatie `permission:orders.delete` to order destroy so sellers (no seeded permission) get 403 while admin/group_leader keep soft-delete within `scopeVisibleTo`.

## Requirements

- Functional: `DELETE /api/orders/{id}` requires `orders.delete`; missing permission → 403 before soft-delete; permitted actors still run existing destroy (`deleted_by` + soft delete + visibility)
- Non-functional: no seeder change; do not add view/update permission middleware

## Architecture

Middleware order: `auth:sanctum` → `permission:orders.delete` → `OrderController::destroy` → `visibleTo` → soft-delete.

Register explicit destroy route with middleware; `apiResource` excepts `store` and `destroy` (mirrors `restore` registration style).

## Related Code Files

- Modify: `routes/api.php`
- Touch only if needed for param consistency: `app/Http/Controllers/Api/OrderController.php` (prefer no controller change)

## Implementation Steps

1. In `routes/api.php`, add `Route::delete('/orders/{id}', [OrderController::class, 'destroy'])->middleware('permission:orders.delete');`
2. Change `apiResource('orders', ...)->except(['store'])` to `except(['store', 'destroy'])`.
3. Keep route order: restore and destroy before or clear of conflicting resource routes (avoid `{order}` binding clashes — use `{id}` like restore).
4. Smoke: seller token DELETE → 403; admin DELETE own-scope → 200.

## Success Criteria

- [x] Destroy route has `permission:orders.delete`
- [x] `apiResource` no longer registers unauthenticated-permission destroy
- [x] Controller soft-delete / `deleted_by` logic unchanged

## Risk Assessment

- Soft-delete Feature tests currently use seller as deleter → will fail until Phase 2 updates actors (expected).
