---
phase: 3
title: "Tests and API docs"
status: pending
priority: P1
effort: "2h"
dependencies: [1, 2]
---

# Phase 3: Tests and API docs

## Overview

Lock soft-delete, deleted_by, restore, trash list, and CSV revive with Feature tests; document DELETE / restore / trash / import revive in `API_DOCS.md`.

## Requirements

- Functional: tests cover happy path + authorization boundaries
- Non-functional: docs match implemented behavior (seller empty body for printify unrelated — do not expand)

## Architecture

New test file preferred: `tests/Feature/OrderSoftDeleteTest.php` (or extend visibility tests if thinner).

## Related Code Files

- Create: `tests/Feature/OrderSoftDeleteTest.php`
- Modify: any existing delete assertions if present
- Modify: `API_DOCS.md` §4.4 (+ short note under CSV import for revive)

## Implementation Steps

1. Test soft delete: actor with visibility deletes → row soft-deleted, `deleted_by` = actor, index excludes it, show 404
2. Test admin `?trashed=only` returns row; seller gets 403
3. Test admin restore → order back on index; `deleted_by` null
4. Test non-admin restore → 403
5. Test CSV import after soft delete → same `id`, not new id; summary `updated`
6. Update `API_DOCS.md`:
   - DELETE is soft delete; fields `deleted_at`, `deleted_by`
   - `POST /orders/{id}/restore` (admin)
   - `GET /orders?trashed=only` (admin)
   - CSV: soft-deleted same Order Number → revive/update
7. Run `php artisan test --filter=OrderSoftDelete` (+ visibility smoke)

## Success Criteria

- [x] Soft-delete / restore / trash / CSV revive tests green
- [x] `API_DOCS.md` §4.4 accurate
- [x] No regression in `OrderVisibilityTest`

## Risk Assessment

- Factories need SoftDeletes-aware helpers — use `Order::factory` or existing import helpers; assert `assertSoftDeleted`
