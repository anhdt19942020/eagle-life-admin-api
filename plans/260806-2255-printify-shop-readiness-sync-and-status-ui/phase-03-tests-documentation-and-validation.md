---
phase: 3
title: "Phase 3: Tests documentation and validation"
status: completed
priority: P1
effort: "1h"
dependencies: [1, 2]
---

# Phase 3: Tests documentation and validation

## Overview

Protect authorization, idempotency, result mapping, readiness semantics, and the cross-repository UI contract. Document the endpoint and operator-visible behavior.

## Requirements

- API feature coverage for success, already-set, unresolved SKU, inactive state, remote failure, permission denial, and ownership denial.
- Readiness resource coverage for every blocker code and account inactivity.
- Assert no outbound request for already-set, inactive, unauthorized, or cross-shop cases.
- Update API docs with route, permission, responses, and the fact that one-product sync only addresses `default_sku`.
- Verify focused Laravel tests and full FE production build.

## Related Code Files

- Create or modify: `D:/Projects/eagle-life-admin-api/tests/Feature/PrintifyShopDefaultSkuSyncApiTest.php`
- Modify: `D:/Projects/eagle-life-admin-api/tests/Feature/PrintifySyncTest.php`
- Modify: `D:/Projects/eagle-life-admin-api/tests/Feature/PrintifyShopOpenTest.php` if query/readiness assertions fit there
- Modify: `D:/Projects/eagle-life-admin-api/API_DOCS.md`

## Implementation Steps

1. Add endpoint tests using `Http::fake()` and existing Printify account/shop helpers.
2. Cover authorization before side effects and idempotent no-op behavior.
3. Assert `ready_for_creation` and `readiness_issues` remain consistent across all gates.
4. Document endpoint response codes and UI interpretation.
5. Run focused API tests, then broader Printify feature tests.
6. Run `npm run build` in `D:/Projects/eagle-life-admin-fe`.
7. Manually smoke one ready row and one blocked row at desktop/mobile widths.

## Test Scenario Matrix

| Scenario | Expected |
|---|---|
| Unique local enabled SKU exists | 200, SKU set, no remote product request |
| No local SKU; first remote product supplies unique SKU | 200, one-product sync, SKU set |
| Existing default SKU | 200 idempotent, SKU unchanged, no remote request |
| Remote product has no unique enabled SKU | 422 `default_sku_not_resolved` |
| Inactive shop/account | 422 stable code, no remote request |
| Missing permission | 403, no state change/request |
| Leader targets another shop | 403, no state change/request |
| Remote exception | 502 generic code; raw message absent from response |
| SKU fixed but approval/sync/open blocker remains | Row remains red and lists remaining blocker |
| All gates satisfied | Row becomes green and says ready |

## Todo

- [ ] Endpoint feature suite passes.
- [ ] Existing Printify readiness/create suites pass.
- [ ] API docs match implementation exactly.
- [ ] FE production build passes.
- [ ] Manual color-plus-text smoke check passes.

## Success Criteria

- Commands:
  - `php artisan test tests/Feature/PrintifyShopDefaultSkuSyncApiTest.php tests/Feature/PrintifySyncTest.php tests/Feature/PrintifyShopOpenTest.php tests/Feature/PrintifyOrderPreviewTest.php`
  - `npm run build` from `D:/Projects/eagle-life-admin-fe`
- No test permits cross-shop side effects.
- Documentation explicitly states that default-product sync does not open a shop, confirm approval, complete order sync, or clear conflicts.

## Risk Assessment

- HTTP fakes can accidentally accept unintended requests. Assert exact URL/method and use `Http::assertNothingSent()` for denied/no-op cases.
- FE lacks automated component tests. Do not add a test framework in this feature; capture build and focused manual checks as the gate.
