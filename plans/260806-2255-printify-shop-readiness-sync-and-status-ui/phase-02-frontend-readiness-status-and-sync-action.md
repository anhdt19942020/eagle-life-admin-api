---
phase: 2
title: "Phase 2: Frontend readiness status and sync action"
status: completed
priority: P1
effort: "2-3h"
dependencies: [1]
---

# Phase 2: Frontend readiness status and sync action

## Overview

Make each shop's readiness obvious and actionable. Ready rows use a restrained green treatment; blocked rows use red. Text labels and blocker details preserve accessibility and explain why syncing one product may not be sufficient.

## Requirements

- Functional: add `printifyService.ensureDefaultSku(shopId)`.
- Functional: show one per-row “Sync 1 SP mặc định” action when the user can manage the shop and `default_sku` is missing.
- Functional: maintain loading state by shop ID; disable duplicate row actions during the request.
- Functional: refresh the shop list after success so readiness and SKU are server-authoritative.
- Functional: show success and normalized API error notifications.
- UX: ready rows are green; blocked rows are red; always pair color with icon/text and blocker labels.
- UX: distinguish “Default SKU missing” from other blockers so the action's purpose is clear.
- UX: do not claim syncing fixes open/manual approval/orders sync/conflict blockers.
- Accessibility: readable contrast, non-color state text, and disabled/loading semantics.

## UI Contract

- Green row: subtle success background/border, `Sẵn sàng tạo đơn`.
- Red row: subtle danger background/border, `Chưa sẵn sàng`.
- Under red state, render Vietnamese labels mapped from `readiness_issues`.
- Sync button appears only for `missing_default_sku` and manageable rows; disable for inactive shop/account.
- After a successful SKU sync, the row may remain red and should display remaining blockers.
- Existing manual “Lưu default SKU”, open/close, and approval actions stay available.

## Related Code Files

- Modify: `D:/Projects/eagle-life-admin-fe/src/services/printifyService.js`
- Modify: `D:/Projects/eagle-life-admin-fe/src/views/printify/PrintifyShopListView.vue`
- Modify: `D:/Projects/eagle-life-admin-fe/src/components/ui/BaseTable.vue` to accept an optional row-class callback; preserve all existing callers.

## Implementation Steps

1. Add the per-shop service method matching the API route.
2. Extend `BaseTable` with an optional `rowClass(row, index)` prop and merge it with existing hover/border classes.
3. Add readiness issue-to-label mapping and a row-class function in the shop view.
4. Replace the plain “Có/Chưa” cell with status label plus blocker details.
5. Add per-row sync state and action, gated by `canManageShop`, missing SKU, and active shop/account.
6. On completion, notify then reload; do not optimistically set readiness.

## Todo

- [ ] Green/red state remains legible without color.
- [ ] One row can load without blocking unrelated read-only UI.
- [ ] Remaining blockers remain visible after SKU sync.
- [ ] Existing shop management actions continue to work.

## Success Criteria

- Every row visibly and textually identifies ready vs blocked.
- Missing requirements are actionable and sourced from API codes.
- Eligible users can sync one product from the affected row.
- Success reloads server state; failures do not mutate local SKU/readiness.
- Existing `BaseTable` consumers render unchanged when `rowClass` is omitted.

## Risk Assessment

- Full red/green fills can overpower the table. Use soft design tokens (`bg-success-soft` / `bg-danger-soft`) and text/border emphasis, not saturated fills.
- Color-only state would fail accessibility. Keep explicit labels and blocker text.
- Frontend has no automated test script. Keep behavior simple and enforce with production build plus manual smoke scenarios in Phase 3.
