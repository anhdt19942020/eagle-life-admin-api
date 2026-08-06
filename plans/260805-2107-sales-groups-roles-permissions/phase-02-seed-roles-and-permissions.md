---
phase: 2
title: "Seed roles and permissions"
status: completed
priority: P1
effort: "2h"
dependencies: [1]
---

# Phase 2: Seed roles and permissions

## Overview

Rewrite `RolePermissionSeeder`: thay roles cũ bằng `admin` / `seller` / `group_leader`; seed English dotted permissions (users, roles.view, sales-groups, orders, printify); sync matrix; migrate users khỏi role cũ.

## Requirements

- Functional: Sau seed chỉ còn 3 roles mới (guard `api`).
- Functional: Permission catalog đầy đủ theo `plan.md` matrix.
- Functional: Migrate assignment: `sale`→`seller`, `manager`→`group_leader`, `buyer`→`seller`; rồi xoá role + permission VN orphan.
- Non-goal: Role CRUD API; wildcard permissions.

## Architecture

```
Permission::firstOrCreate (English names)
Role::firstOrCreate admin|seller|group_leader
Reassign model_has_roles from old → new
Delete old roles manager|sale|buyer
Delete obsolete VN permission names
admin->syncPermissions(all)
group_leader / seller -> syncPermissions(matrix)
forgetCachedPermissions()
```

### Matrix (authoritative)

| Permission | admin | group_leader | seller |
|------------|:----:|:------------:|:------:|
| users.* | ✅ | ❌ | ❌ |
| roles.view | ✅ | ❌ | ❌ |
| sales-groups.* | ✅ | ❌ | ❌ |
| orders.view/create/update | ✅ | ✅ | ✅ |
| orders.delete | ✅ | ✅ | ❌ |
| orders.import | ✅ | ✅ | ✅ |
| orders.export | ✅ | ✅ | ❌ |
| printify.* (existing set) | ✅ | ✅ | ❌ |

## Related Code Files

- Modify: `database/seeders/RolePermissionSeeder.php`
- Touch if needed: `database/seeders/AdminUserSeeder.php` (vẫn `assignRole('admin')`)

## Implementation Steps

1. Define canonical permission list (English only for admin-domain + orders CRUD rename).
2. Create new roles; migrate user role rows from old names.
3. `syncPermissions` per matrix; admin gets all.
4. Delete obsolete roles/permissions; clear Spatie cache.
5. Smoke: `php artisan db:seed --class=RolePermissionSeeder` on empty + existing DB.

## Success Criteria

- [x] `Role::pluck('name')` = admin, seller, group_leader only
- [x] Admin has users + sales-groups permissions
- [x] Seller lacks users/sales-groups permissions
- [x] Existing admin seed users still `hasRole('admin')`

## Risk Assessment

Medium — live DB với role cũ. Mitigation: migrate assignments trước khi delete. Document: re-seed trên staging trước prod.
