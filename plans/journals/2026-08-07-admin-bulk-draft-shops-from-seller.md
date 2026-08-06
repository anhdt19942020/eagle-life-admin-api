# 2026-08-07 — Admin bulk draft: shops from seller, picker disabled

## Context
Admin multi-select "Tạo đơn nháp Printify" still showed a full-shop radio picker
and applied one `shop_id` to the whole batch, even though each order already maps
to a seller with an assigned Printify shop.

## Root cause
- FE bulk modal always rendered `PrintifyShopPicker` for admin and reused a single
  `selectedShopId`.
- Order list payload only loaded `seller:id,name,employee_code`, so the UI could
  not derive Order → Seller → Shop without an extra join.

## Fix
- API `OrderController` eager-loads `seller.printifyShop` on index/show/update/restore.
- FE admin bulk modal shows a read-only deduped shop list (with order counts) and
  sends each create with that seller's shop id.
- Confirm is blocked when any selected order lacks a resolved seller shop.
- Admin single-create picker unchanged; seller/leader bulk still uses assigned shop.

## Verification
- `OrderShowHttpTest` (+ blast-radius order/printify create/visibility/auth): pass.
- Code review: no must-fix blockers.
