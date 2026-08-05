---
phase: 1
title: "Schema sales_groups"
status: completed
priority: P1
effort: "1.5h"
dependencies: []
---

# Phase 1: Schema sales_groups

## Overview

Tạo bảng `sales_groups` và cột nullable `users.sales_group_id` (Option 1 FK). Align/finish draft migration đã có nếu còn khớp design.

## Requirements

- Functional: CRUD-ready table với `name`, `platform`, optional unique `code`, `status`, timestamps.
- Functional: `users.sales_group_id` FK → `sales_groups`, nullable, `nullOnDelete` ở DB; app sẽ chặn xoá khi còn member.
- Non-functional: Portable SQLite/MySQL — `platform` là string + app validate, không ENUM.
- Non-goal: Pivot multi-group; Spatie teams.

## Architecture

```
sales_groups (id, name, platform, code?, status, timestamps)
users.sales_group_id → sales_groups.id (nullable)
INDEX sales_groups(platform)
```

`SalesGroup` model: fillable, boolean cast `status`, `PLATFORMS` const, `users()` HasMany.  
`User`: fillable `sales_group_id`, `belongsTo(SalesGroup)`.

## Related Code Files

- Create/finish: `database/migrations/2026_08_05_000015_create_sales_groups_and_add_user_sales_group.php`
- Create/finish: `app/Models/SalesGroup.php`
- Modify: `app/Models/User.php`

## Implementation Steps

1. Review draft migration; ensure `code` unique nullable, `platform` indexed, FK on users.
2. Finish `SalesGroup` model (`PLATFORMS = ebay|tiktok|amazon`).
3. Add `sales_group_id` to User `$fillable` + `salesGroup()` relation.
4. Run migrate locally / confirm tests can migrate.

## Success Criteria

- [x] `php artisan migrate` succeeds on project DB
- [x] Schema has `sales_groups` + `users.sales_group_id` FK
- [x] Model relations load without error

## Risk Assessment

Low. Rollback: drop FK then table. Existing users keep `sales_group_id` null until phase 3 validation.
