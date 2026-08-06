---
phase: 1
title: "Schema + soft destroy + deleted_by"
status: pending
priority: P1
effort: "2-3h"
dependencies: []
---

# Phase 1: Schema + soft destroy + deleted_by

## Overview

Add SoftDeletes columns and wire `DELETE /orders/{id}` so deletes set `deleted_by` and soft-delete instead of removing the row.

## Requirements

- Functional: migration adds `deleted_at`, `deleted_by` (nullable FK `users`, `nullOnDelete`); `Order` uses SoftDeletes; destroy records actor then soft-deletes; default queries exclude trashed
- Non-functional: no behavior change for list/show filters beyond excluding trashed; visibility scope still applies on non-trashed rows

## Architecture

- Trait `Illuminate\Database\Eloquent\SoftDeletes` on `Order`
- Relation `deletedBy(): BelongsTo User`
- `destroy`: `visibleTo` → set `deleted_by` → `$order->delete()`
- Do **not** soft-delete child tables; line items / address remain attached to parent (parent hidden via SoftDeletes)

## Related Code Files

- Create: `database/migrations/xxxx_add_soft_deletes_to_orders_table.php`
- Modify: `app/Models/Order.php`
- Modify: `app/Http/Controllers/Api/OrderController.php` (`destroy`)

## Implementation Steps

1. Migration: `$table->softDeletes();` + `$table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();`
2. Model: SoftDeletes trait; fillable/casts as needed (`deleted_by` not mass-assign from request); `deletedBy()` relation
3. `destroy`: after `findOrFail`, `forceFill(['deleted_by' => $request->user()->id])->save()` then `delete()`
4. Smoke: soft-deleted id absent from `Order::query()->visibleTo($user)` and present in `Order::withTrashed()`

## Success Criteria

- [x] Migration runs up/down cleanly
- [x] Soft delete leaves row with `deleted_at` + `deleted_by`
- [x] Default index/show no longer return trashed orders
- [x] Hard delete path no longer used for HTTP destroy

## Risk Assessment

- Mass-assignment of `deleted_by` from client → keep off `$fillable`, set only in controller
- Existing tests that assert row gone after delete → update in phase 3
