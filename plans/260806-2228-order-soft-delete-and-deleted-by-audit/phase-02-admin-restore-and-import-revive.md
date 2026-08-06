---
phase: 2
title: "Admin restore and import revive"
status: pending
priority: P1
effort: "2-3h"
dependencies: [1]
---

# Phase 2: Admin restore and import revive

## Overview

Let admins list and restore soft-deleted orders, and make CSV import revive a trashed row with the same `ebay_order_number` instead of failing unique or creating a conflicting insert.

## Requirements

- Functional: admin-only trash index filter; admin-only restore endpoint; CSV `persistCsvOrder` uses `withTrashed()` and restores when needed
- Non-functional: non-admin cannot list/restore trash (404/403); seller attribution rules unchanged after revive

## Architecture

**Trash list:** `GET /orders?trashed=only` — if admin, `onlyTrashed()` then `visibleTo` (admin sees all). Non-admin → ignore or 403; prefer **403** when `trashed` requested by non-admin.

**Restore:** `POST /orders/{id}/restore` (admin only). Load `withTrashed()->findOrFail`, `restore()`, clear `deleted_by` (null), return order payload.

**CSV revive** (critical for unique keys):

```php
$order = Order::withTrashed()->where('ebay_order_number', $number)->first();
$created = $order === null;
if ($order?->trashed()) {
    $order->restore();
    $order->forceFill(['deleted_by' => null])->save();
}
// then existing upsert/fill path; seller_id assign if created || seller_id null
```

Adjust `created` / `updated` counting: revive counts as **updated** (row existed), not created — or document as `updated` with note. Prefer **updated** for summary honesty.

Route binding: register restore **before** or outside `{order}` binding that excludes trashed; use `{id}` + explicit `withTrashed` query.

## Related Code Files

- Modify: `app/Http/Controllers/Api/OrderController.php` (`index` trashed filter, `restore`)
- Modify: `routes/api.php` — `POST /orders/{id}/restore`
- Modify: `app/Services/OrderImportService.php` — `persistCsvOrder` withTrashed/revive
- Optional: response includes `deleted_at` / `deleted_by` / `deleted_by_user` only when trash listing

## Implementation Steps

1. Add `restore(Request, $id)` — admin gate (`hasRole('admin')`), else 403
2. Wire route `POST /orders/{id}/restore`
3. Index: if `$request->trashed === 'only'` and admin → `onlyTrashed()`; non-admin → 403
4. Import: `withTrashed` lookup; restore + clear `deleted_by` before fill/save
5. Ensure Printify routes still 404 for trashed orders (default SoftDeletes on `{order}` binding / re-query)

## Success Criteria

- [x] Admin lists trash via `?trashed=only`
- [x] Admin restore clears `deleted_at`/`deleted_by`; order returns to normal list
- [x] Non-admin restore / trash list denied
- [x] CSV re-import of soft-deleted ebay number restores same primary key

## Risk Assessment

- `upsert` ignoring SoftDeletes and updating wrong columns → prefer explicit Eloquent path after withTrashed find, keep upsert only for brand-new inserts or refactor carefully
- Double-count `created` after revive → count as updated
