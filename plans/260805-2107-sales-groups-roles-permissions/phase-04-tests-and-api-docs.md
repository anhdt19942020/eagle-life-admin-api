---
phase: 4
title: "Tests and API docs"
status: completed
priority: P1
effort: "2h"
dependencies: [3]
---

# Phase 4: Tests and API docs

## Overview

Feature tests cho authorization + validation invariants; cập nhật `API_DOCS.md` (roles list, user payload `sales_group_id`, sales-groups section, permission notes).

## Requirements

- Functional: Tests cover admin allow / seller forbid / group required / delete group with members.
- Functional: Docs match live routes and seed role names (`admin`, `seller`, `group_leader`).
- Non-goal: Full permission matrix E2E for every printify route.

## Architecture

```
tests/Feature/SalesGroupApiTest.php
tests/Feature/UserRoleGroupValidationTest.php
(optional) UserAuthorizationTest.php
Pattern: DatabaseMigrations + Sanctum::actingAs + Role/Permission seed or findOrCreate
```

## Related Code Files

- Create: `tests/Feature/SalesGroupApiTest.php`
- Create: `tests/Feature/UserRoleGroupValidationTest.php`
- Modify: `API_DOCS.md` (sections 2.3 roles, 3 users, new sales-groups section)
- Optionally: `database/factories` if SalesGroup factory helps

## Implementation Steps

1. Seed minimal roles/permissions in tests (or call RolePermissionSeeder).
2. Assert sales-groups CRUD + 403 + delete-with-members 422.
3. Assert user create seller requires group; admin omits group; users routes 403 for seller.
4. Update API_DOCS examples (replace manager/sale/support).
5. Run `php artisan test --filter=SalesGroup|UserRole`.

## Success Criteria

- [x] New feature tests pass
- [x] Existing Printify/order auth tests still pass (permission names they create locally unchanged)
- [x] API_DOCS documents platforms, roles, `sales_group_id`, and admin-only permission gate

## Risk Assessment

Low. Watch tests that assumed open `/users` without permission — none found in Feature suite today; Printify tests create own permissions.
