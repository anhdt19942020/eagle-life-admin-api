---
phase: 3
title: "Wire APIs and middleware"
status: completed
priority: P1
effort: "3h"
dependencies: [1, 2]
---

# Phase 3: Wire APIs and middleware

## Overview

Hoàn thiện SalesGroup CRUD, User create/update với rule nhóm theo role, Role list gated, đăng ký middleware `permission` trên routes. `/me` và login load `salesGroup`.

## Requirements

- Functional: `/api/sales-groups` full apiResource; filter `platform`, `status`, `search`.
- Functional: User store/update: `role` required-or-optional per existing UX; nếu role ∈ {seller, group_leader} → `sales_group_id` required + exists; nếu admin → force `sales_group_id` null.
- Functional: Xoá sales group 422 nếu còn users.
- Functional: Routes gated by matching `permission:*` (not `role:admin`).
- Non-goal: Multi-role request body (`roles[]`) — giữ field `role` singular; Spatie vẫn multi-capable later.

## Architecture

```
bootstrap: alias permission (already) — ensure RoleMiddleware not required
routes:
  users.*        → permission:users.{view|create|update|delete}
  roles index    → permission:roles.view
  sales-groups.* → permission:sales-groups.{view|create|update|delete}
AuthController login/me → load roles.permissions + salesGroup
```

Validation helper (private on UserController or Form Request): resolve effective role(s) then apply group invariant.

## Related Code Files

- Finish: `app/Http/Controllers/Api/SalesGroupController.php`
- Modify: `app/Http/Controllers/Api/UserController.php`
- Modify: `app/Http/Controllers/Api/RoleController.php` (middleware via routes only OK)
- Modify: `app/Http/Controllers/Api/AuthController.php`
- Modify: `routes/api.php`
- Modify if needed: `bootstrap/app.php` (permission alias already present)

## Implementation Steps

1. Finish SalesGroupController validation (`Rule::in(SalesGroup::PLATFORMS)`), destroy guard.
2. UserController: with `salesGroup`; validate `sales_group_id`; assignRole/syncRoles; clear group for admin.
3. Wire routes with per-action permission middleware (group nested under auth:sanctum).
4. Auth login/me eager-load `salesGroup`.
5. Index users: optional filter `sales_group_id`.

## Success Criteria

- [x] Admin token can CRUD sales-groups and users
- [x] Seller token gets 403 on those routes
- [x] Creating seller without `sales_group_id` → 422
- [x] Creating admin with `sales_group_id` clears/rejects to null

## Risk Assessment

Medium — any authenticated user currently hits `/users`. Locking down may break FE if non-admin was used in dev; expected per contract. Coordinate FE to use admin for staff screens.
