---
phase: 1
title: "Backend endpoint and readiness contract"
status: completed
priority: P1
effort: "2-3h"
dependencies: []
---

# Phase 1: Backend endpoint and readiness contract

## Overview

Expose the existing one-shop default-SKU workflow over HTTP and make readiness reasons explicit and consistent with order creation.

## Requirements

- Functional: `POST /api/printify/shops/{shop}/ensure-default-sku` accepts no body and runs synchronously.
- Functional: enforce `printify.shop-readiness.confirm` and `PrintifyShopPolicy::manage`.
- Functional: do not overwrite an existing SKU; do not call Printify when already configured.
- Functional: reject inactive shop/account before outbound work.
- Functional: return stable machine-readable outcomes for `already_set`, `no_unique_enabled_sku`, inactive shop/account, and remote failure.
- Functional: expose `readiness_issues` alongside `ready_for_creation`.
- Non-functional: avoid one conflict query per resource row by loading a conflict aggregate in the index query.
- Security: never expose API keys or raw exception messages.

## Architecture

Keep one readiness authority on `PrintifyShop`:

- `readinessIssues()` returns stable codes such as `shop_inactive`, `account_inactive`, `shop_closed`, `missing_default_sku`, `manual_approval_required`, `orders_sync_incomplete`, and `order_conflicts`.
- `isReadyForCreation()` delegates to `readinessIssues()->isEmpty()` so boolean and blockers cannot drift.
- `PrintifyShopController::index()` eager-loads account and a filtered conflict existence aggregate.
- `PrintifyShopResource` emits both fields.

Endpoint result mapping:

| Ensurer outcome | HTTP | Stable code |
|---|---:|---|
| `set` | 200 | `default_sku_set` |
| `skipped/already_set` | 200 | `default_sku_already_set` |
| inactive shop/account | 422 | `printify_shop_not_ready` / `printify_account_inactive` |
| no unique enabled SKU after one-product sync | 422 | `default_sku_not_resolved` |
| unexpected remote failure | 502 | `default_sku_sync_failed` |

## Related Code Files

- Modify: `D:/Projects/eagle-life-admin-api/routes/api.php`
- Modify: `D:/Projects/eagle-life-admin-api/app/Http/Controllers/Api/PrintifyShopController.php`
- Modify: `D:/Projects/eagle-life-admin-api/app/Models/PrintifyShop.php`
- Modify: `D:/Projects/eagle-life-admin-api/app/Http/Resources/PrintifyShopResource.php`
- Modify only if direct-call guards belong at service boundary: `D:/Projects/eagle-life-admin-api/app/Services/Printify/PrintifyDefaultSkuEnsurer.php`

## Implementation Steps

1. Add stable readiness issue generation and make `isReadyForCreation()` reuse it.
2. Include inactive account in readiness, matching the existing preview/create preflight.
3. Add an eager conflict aggregate to the shop index and consume it before falling back to a direct query.
4. Add controller action with policy authorization and deterministic result mapping.
5. Register the route under existing permission middleware.
6. Return refreshed resource data after a successful/idempotent operation.

## Todo

- [ ] Readiness boolean and issue codes share one implementation.
- [ ] Endpoint is idempotent and ownership-scoped.
- [ ] Outbound error details remain server-side.
- [ ] Shop list query has bounded query count.

## Success Criteria

- Authorized per-shop sync sets one unique enabled SKU and returns updated readiness.
- Closed shops may prepare a SKU but remain blocked by `shop_closed`; the endpoint does not silently open them.
- Inactive shop/account and unresolved SKU return stable `422` codes.
- Cross-shop non-admin access returns `403` before Printify is called.

## Risk Assessment

- Synchronous Printify latency may approach request timeout. Keep sync limited to one product and return `502` on failure; move to a job only if observed latency requires polling.
- Adding account status to readiness can turn previously green rows red. This is intentional because order preview already rejects inactive accounts.
- Existing resource conflict checks are N+1. The aggregate must preserve the exact `has_conflict=true` semantics.
