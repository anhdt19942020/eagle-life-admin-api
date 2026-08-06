---
phase: 1
title: "Persist and expose sales channel"
status: pending
priority: P1
effort: "30-45m"
dependencies: []
---

# Phase 1: Persist and expose sales channel

## Overview

Add the `sales_channel` column, capture it during shop sync, and emit it on the shop resource.

## Requirements

- Functional: `printify_shops` gains a nullable `sales_channel` string positioned after `title`.
- Functional: `syncShops()` writes the value from the Printify payload on both create and update.
- Functional: a payload without a `sales_channel` key stores `null` rather than throwing.
- Functional: `PrintifyShopResource` emits `sales_channel` after `title`.
- Non-functional: no change to any existing column, response key, or status code.
- Non-functional: the migration is reversible via `dropColumn`.

## Architecture

`PrintifyShop::$fillable` must include `sales_channel` — the sync path is `updateOrCreate`, which mass-assigns, so an unfillable attribute would be dropped silently rather than error.

Reactivation handling stays untouched. The existing block that resets `orders_sync_state` and manual-approval fields when a shop comes back is about local operator state; `sales_channel` is remote truth rewritten on every sync and must not be conditional on reactivation.

## Related Code Files

- Create: `database/migrations/2026_08_07_000019_add_sales_channel_to_printify_shops_table.php`
- Modify: `app/Models/PrintifyShop.php` (add to `$fillable`)
- Modify: `app/Services/Printify/PrintifySyncService.php` (the `$attributes` map in `syncShops()`, around line 45)
- Modify: `app/Http/Resources/PrintifyShopResource.php` (after the `title` key)

## Implementation Steps

1. Write the migration following the `add_default_sku_to_printify_shops_table` pattern: `$table->string('sales_channel')->nullable()->after('title');` with a `dropColumn` down.
2. Add `'sales_channel'` to `PrintifyShop::$fillable`. No cast — it is a plain string.
3. In `syncShops()`, add `'sales_channel' => $shop['sales_channel'] ?? null,` to the unconditional `$attributes` array, not the reactivation-only block.
4. Add `'sales_channel' => $this->sales_channel,` to `PrintifyShopResource::toArray()` directly after `title`.
5. Run `php artisan migrate` against the local database.

## Todo

- [ ] Migration created and reversible.
- [ ] `sales_channel` is mass-assignable.
- [ ] Sync writes the value on both the create and the update path.
- [ ] Resource emits the field.

## Success Criteria

- A sync against a payload containing `sales_channel` persists that exact string.
- A sync against a payload missing the key persists `null` and completes.
- `GET /api/printify/shops` includes `sales_channel` on each item.
- `php artisan migrate:rollback --step=1` drops the column cleanly.

## Risk Assessment

- Forgetting `$fillable` fails silently: `updateOrCreate` ignores the attribute and every row stays `null`, with no error. Phase 2's persistence assertion is what catches this.
- Placing the attribute inside the reactivation-only block would leave already-active shops stale forever. Keep it in the unconditional map.
- The migration is additive and nullable, so it is safe to run against the 393 existing rows with no downtime and no data rewrite.
