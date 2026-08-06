---
title: "Printify shop readiness sync and status UI"
description: "Add a per-shop default-product sync action and make order readiness immediately visible and actionable in the Printify shop list."
status: completed
priority: P1
effort: "5-7h"
branch: "main"
tags: [feature, backend, frontend, api, printify]
blockedBy: []
blocks: []
created: 2026-08-06
---

# Printify shop readiness sync and status UI

## Overview

Expose the existing `PrintifyDefaultSkuEnsurer::ensureForShop()` workflow through a secured per-shop API. Update the Printify shop table so ready shops are visibly green, blocked shops visibly red, and missing requirements are stated in text. A row-level action syncs at most one product and assigns a unique enabled SKU as `default_sku`.

The plan covers both repositories:

- API: `D:/Projects/eagle-life-admin-api`
- FE: `D:/Projects/eagle-life-admin-fe`

## Scope

| In scope | Out of scope |
|---|---|
| Synchronous per-shop ensure-default-SKU endpoint | Full product catalog sync |
| Stable result/error codes and ownership authorization | Automatic open/manual approval/orders sync |
| `ready_for_creation` plus actionable `readiness_issues` | Changing Printify readiness business rules |
| Green/red row treatment with text status | New design system or frontend test framework |
| API feature tests, API docs, FE production build | Background polling or bulk ensure action |

## Contract

- Endpoint: `POST /api/printify/shops/{shop}/ensure-default-sku`.
- Permission: `printify.shop-readiness.confirm`; `PrintifyShopPolicy::manage` remains the ownership boundary.
- No request body. Only active shops under active accounts can trigger outbound Printify calls.
- The endpoint reuses `PrintifyDefaultSkuEnsurer`; it first uses an already-synced unique enabled SKU, otherwise syncs at most one product and retries.
- The operation is idempotent. Existing `default_sku` is never overwritten.
- Success returns the refreshed shop resource plus `{ status, sku, reason }`.
- Expected inability to derive a unique SKU returns `422` with a stable code; unexpected remote failure returns a generic `502` code while details remain in logs.
- `ready_for_creation` must reflect the same effective gates as order creation, including active account. `readiness_issues` supplies stable blocker codes.
- UI never communicates state by color alone: green/red treatment is paired with “Sẵn sàng” / “Chưa sẵn sàng” and blocker text.

## Architecture

```text
Shop row action
  → POST /shops/{shop}/ensure-default-sku
  → permission middleware + PrintifyShopPolicy::manage
  → PrintifyDefaultSkuEnsurer::ensureForShop(seedProduct: true)
  → existing local SKU OR Printify syncProducts(page=1, limit=1)
  → persist default_sku
  → refreshed PrintifyShopResource
  → reload row/list and recompute green/red readiness state
```

Avoid a new job/service: the existing ensurer already encapsulates selection, one-product sync, logging, and idempotency. Per-shop synchronous execution gives the button a deterministic result without polling.

## Goals

| # | Goal | Priority |
|---|------|----------|
| 1 | Secure per-shop default-product sync API | P1 |
| 2 | Actionable readiness contract (`ready_for_creation`, blocker codes) | P1 |
| 3 | Accessible green/red shop-list UI with per-row sync action | P1 |
| 4 | Regression tests, API docs, and FE build verification | P1 |

## Phases

| # | Phase | Status |
|---|-------|--------|
| 1 | [Backend endpoint and readiness contract](./phase-01-start.md) | Completed |
| 2 | [Frontend readiness status and sync action](./phase-02-frontend-readiness-status-and-sync-action.md) | Completed |
| 3 | [Tests documentation and validation](./phase-03-tests-documentation-and-validation.md) | Completed |

## Dependencies

- Reuses the implemented foundation from `260805-2319-printify-multi-account-management`; no unfinished phase is a hard runtime blocker.
- Requires a working queue only for existing account-wide shop sync, not for this per-shop endpoint.
- API changes must ship before the FE button.

## Success Criteria

- [x] Authorized admin/assigned leader can sync one product for one manageable shop and obtain a default SKU.
- [x] Unauthorized or cross-shop calls produce `403` and no outbound request.
- [x] Existing default SKU is preserved without an outbound request.
- [x] Readiness includes account status and returns stable blocker codes without N+1 conflict queries on the list.
- [x] Shop rows show accessible green/red state, blocker text, and correct per-row loading behavior.
- [x] API feature tests pass and `npm run build` succeeds in the FE repository.

<!-- slug: printify-shop-readiness-sync-and-status-ui -->