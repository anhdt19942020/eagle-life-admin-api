---
phase: 2
title: "Account and user assignment API"
status: completed
priority: P1
effort: "7-8h"
dependencies: [1]
---

# Phase 2: Account and user assignment API

## Overview

Expose admin-only account management and extend user create/update plus `/me` responses with safe Printify assignment metadata. Enforce the one-user/one-shop rule at validation and database boundaries, while persisting only the canonical shop FK.

## Context links

- `routes/api.php:16-54`
- `app/Http/Controllers/Api/UserController.php:13-172`
- `app/Http/Controllers/Api/AuthController.php:35-53`
- `database/seeders/RolePermissionSeeder.php:13-83`
- `app/Http/Resources/PrintifyShopResource.php:8-27`
- [Laravel authorization](https://laravel.com/docs/11.x/authorization)
- [Laravel scoped exists validation](https://laravel.com/docs/11.x/validation#available-validation-rules)

## Key insights

- Existing route protection uses Spatie permission middleware, not hardcoded role middleware. Add account permissions and grant them only to admin.
- The frontend needs account + shop selectors, but `users.printify_shop_id` is the source of truth. The API accepts/validates the selected account as context and verifies the shop belongs to it; it does not persist a duplicate account FK on users.
- Existing model serialization is broad. `PrintifyAccount::$hidden` plus explicit account resources are required to keep encrypted `api_key` out of login, `/me`, user, shop, and account responses.

## Requirements

- Functional: admin can list/create/show/update/deactivate/reactivate accounts.
- Functional: account create requires email + API key; update may omit API key to retain it or provide a replacement.
- Functional: seller/group leader create/update requires one active account and one active shop belonging to that account.
- Functional: no two users may hold the same non-null shop assignment; a role change to admin clears the assignment.
- Functional: seller receives `printify.order.create`; group leader retains it.
- Non-functional: validation returns standard Laravel JSON `422`; authorization returns `401/403` using existing middleware.
- Security: no raw API key in any response, log, validation message, or frontend state.

## Architecture

```text
Admin account API ──permission:printify.accounts.*──> PrintifyAccountResource
User store/update ──validate account + shop──> users.printify_shop_id
GET /me + user APIs ──safe eager load──> printify_shop { title, account_id }
```

Account status changes do not clear assignments. The order integration phase will reject calls through inactive accounts, preserving the assignment for later reactivation.

## API contract

| Method | Path | Permission | Contract |
|---|---|---|---|
| GET | `/printify/accounts` | `printify.accounts.view` | Paginated safe accounts; `has_api_key`, counts, status; never key |
| POST | `/printify/accounts` | `printify.accounts.manage` | Email + API key; returns safe resource |
| GET | `/printify/accounts/{account}` | `printify.accounts.view` | Safe account detail and related shop summaries |
| PATCH | `/printify/accounts/{account}` | `printify.accounts.manage` | Email; optional replacement API key |
| POST | `/printify/accounts/{account}/deactivate` | `printify.accounts.manage` | Sets inactive, keeps assignments/data |
| POST | `/printify/accounts/{account}/activate` | `printify.accounts.manage` | Sets active, keeps assignments/data |
| GET | `/printify/shops?account_id={id}` | existing catalog permission | Filters shops for account/user-form selection |

Shop sync remains on the existing `POST /printify/shops/sync` route and gains required `account_id` context in Phase 3. Per the plan-level ownership decision, `printify.sync` becomes admin-only in this phase's seeder update (Implementation Step 1) — group leaders keep shop-scoped readiness actions, not account-wide sync.

For user store/update, accept `printify_account_id` as selection context and `printify_shop_id` as the persisted assignment. Validate both together with an account-scoped `exists` rule and a unique user-shop rule; derive the account from the shop after validation.

## File inventory

| Action | File | Test impact |
|---|---|---|
| Create | `app/Http/Controllers/Api/PrintifyAccountController.php` | Account CRUD/status contract |
| Create | `app/Http/Resources/PrintifyAccountResource.php` | Allowlisted safe account fields |
| Modify | `app/Http/Controllers/Api/UserController.php` | Assignment validation, persistence, role transitions |
| Modify | `app/Models/User.php` | Assignment relation and safe relation loading |
| Read/use | `app/Models/PrintifyAccount.php` | Phase 1 model; resource may use relationship counts without expanding credential surface |
| Read/use | `app/Models/PrintifyShop.php` | Phase 1 account relationship and assignment scopes |
| Modify | `app/Http/Controllers/Api/PrintifyShopController.php` | Account filter for admin user form |
| Modify | `app/Http/Resources/PrintifyShopResource.php` | Safe account/shop metadata, no credential |
| Modify | `app/Http/Controllers/Api/AuthController.php` | Safe assignment in login and `/me` |
| Modify | `routes/api.php` | Account routes and assignment-capable existing routes |
| Modify | `database/seeders/RolePermissionSeeder.php` | Admin account permissions; seller order permission |
| Verify later | `tests/Feature/PrintifyAccountApiTest.php` | Phase 5 |
| Verify later | `tests/Feature/PrintifyUserAssignmentTest.php` | Phase 5 |

## Implementation Steps

1. Add account permissions to the permission catalog and admin matrix; add `printify.order.create` to seller. In the same `syncPermissions` matrix, **remove `printify.sync` from `group_leader`** (`RolePermissionSeeder.php:73-79` currently grants it) so account-wide catalog sync is admin-only; `group_leader` keeps `printify.catalog.view`, `printify.shop-readiness.confirm`, `printify.order.create`, and `printify.reconcile`, which Phase 3 constrains by shop ownership rather than by permission.
2. Implement `PrintifyAccountResource` with explicit fields: id, email, is_active, has_api_key, shop_count, assigned_user_count, timestamps. Never serialize the model directly from account endpoints.
3. Implement account controller validation and status actions. On update, only replace the encrypted key when a non-empty key is submitted; do not echo it in errors or success responses.
4. Add account filtering to shop listing and ensure only active accounts/shops are offered for new user assignment. Keep closed shops visible to admin with readiness state when appropriate.
5. Extend `UserController::store/update` with account/shop validation, unique assignment handling, role-aware clearing, and relations in returned data. Keep an inactive user's assignment reserved until an admin explicitly clears it.
6. Load only safe `printifyShop`/account metadata in login and `/me`; verify a serialized user cannot include `api_key`. `UserController.php:90` and `:154` return `$user->load([...])` as a raw model with no Resource, so `PrintifyAccount::$hidden` is the load-bearing protection here, not a nicety — assert its absence in the response body, not just in the model.
7. Add the assignment relation to every existing eager-load list rather than lazy-loading it: `UserController.php:18`, `:95` (`User::with(['roles','salesGroup'])` → add `printifyShop.account`) and the shop index in Phase 3. A paginated user or shop list must not gain one query per row.
8. Add routes and middleware. Do not add a hard-delete account route.
9. Add stable error data for missing assignment. `App\Traits\ApiResponse::error()` returns `{status, message, data}`, so the code goes inside `data` and the exact envelope is:

```json
{ "status": "error", "message": "<vi message>", "data": { "code": "printify_shop_assignment_required" } }
```

The stable codes are `printify_shop_assignment_required`, `printify_account_inactive`, and `printify_shop_not_ready`. The frontend reads `response.data.data.code` and must never branch on `message`, which is Vietnamese display text and may be reworded.

## Test scenario matrix

| Scenario | Expected result | Verification owner |
|---|---|---|
| Anonymous/account permissionless account request | `401`/`403` | Phase 5 account API test |
| Admin creates account | `201`, safe response, encrypted key stored | Phase 5 account API test |
| Update without API key | Email/status changes; existing key retained | Phase 5 account API test |
| Deactivate then activate | Status toggles; no rows deleted | Phase 5 account API test |
| Seller create without assignment | `422` with stable assignment code | Phase 5 user/order test |
| Seller create with account/shop mismatch | `422`; no assignment persisted | Phase 5 assignment test |
| Two users choose same shop | `422` or DB unique failure normalized to validation | Phase 5 assignment test |
| Role changes seller → admin | Assignment is cleared | Phase 5 assignment test |
| Login or `/me` with assignment | Shop title/id appears; API key absent | Phase 5 auth test |
| Seller permission matrix | `printify.order.create` allowed; account management forbidden | Phase 5 authorization test |

## Function/interface checklist

- [x] Account routes use existing Sanctum + permission middleware.
- [x] Account resource is allowlisted and write-only for `api_key`.
- [x] User assignment accepts account context but stores only the canonical shop FK.
- [x] Unique assignment validation ignores the current user on update.
- [x] Role transitions cannot leave a seller/leader without a shop.
- [x] Assignment metadata is safe in login, `/me`, user list/detail, and shop responses.

## Dependency map

- Phase 1 provides schema/model/encryption primitives.
- Phase 2 provides account lookup, assignment validation, seller permission, and API contracts consumed by Phases 3–4.
- Phase 3 must reuse the same `PrintifyAccount` and `PrintifyShop::account()` relations; do not create a second assignment resolver.
- Phase 4 uses account list + filtered shop list for admin user forms and `/me` assignment metadata for orders.

## Success Criteria

- [x] Admin-only account CRUD/status endpoints work and never return the API key.
- [x] Seller/group leader assignment is required and unique per shop.
- [x] Account/shop mismatch and duplicate assignment are rejected before persistence.
- [x] Seller has the order-create permission; non-admin account APIs return `403`.
- [x] Login, `/me`, and user responses expose the assigned shop name without credential leakage.

## Risk Assessment

- Broad existing user serialization may accidentally expose nested credentials. Mitigation: hide the model field and use explicit resources/relations; add response assertions.
- Concurrent assignments can race between validation and insert. Mitigation: keep the database unique index as the final authority and normalize duplicate-key exceptions to `422`.
- Clearing assignments on role transition can surprise an admin. Mitigation: document the behavior in the user form and return the updated assignment state.

## Implementation record

- Code-reviewer subagent (2026-08-05) found 1 High + 2 Medium + 1 Low issue, all fixed and re-verified before this phase was marked complete:
  1. **(High)** Implementation Step 9's stable error codes (`printify_shop_assignment_required`, `printify_account_inactive`, `printify_shop_not_ready`) were completely unimplemented — the assignment business-rule check only added plain Laravel field-error messages, which the app's `ValidationException` renderer serializes as `{"data": {"printify_shop_id": [...]}}`, not `{"data": {"code": "..."}}`. Fixed by removing `printify_shop_id`'s `Rule::requiredIf` from the Laravel validator (kept only structural `nullable|integer|exists|unique` there) and adding an explicit post-validation business-rule check (`UserController::resolvePrintifyAssignmentError()`) that returns the three named codes directly via `$this->error($message, 422, ['code' => $code])`, bypassing the standard validation-exception path for exactly these three conditions. Verified empirically for all three codes plus the success path via a live server.
  2. **(Medium)** The `UniqueConstraintViolationException` catch in `UserController::store/update` unconditionally attributed every race-condition failure to the shop assignment, but `email`/`username`/`phone` share the same validate-then-write TOCTOU window and would have been misreported. Fixed with `respondToUniqueViolation()`, which re-queries each unique-constrained column after the exception to report the column that actually collided.
  3. **(Medium)** `PrintifyAccountController::store/update` swallowed all exceptions with no logging at all, making a genuine bug (bad encryption key, DB outage) invisible in `storage/logs`. Fixed with `Log::error()` calls that log the exception class and a non-secret identifier (email on create, account id on update) — deliberately never `$e->getMessage()`, since `QueryException` interpolates bound values (including `api_key`) into its message text.
  4. **(Low)** The assignment business-rule check ran even when the target role was admin (whose `printify_shop_id` is unconditionally discarded), causing an avoidable false-rejection if a client sent stale form data. `resolvePrintifyAssignmentError()` returns `null` immediately for any role outside `User::GROUP_REQUIRED_ROLES`, so admin creation/update is never blocked by shop-assignment state. Verified empirically: admin create with a stale reference to an inactive shop now succeeds and the field is correctly nulled.
- Verification performed: full existing test suite (82/82, 268 assertions) and Pint clean on every touched file after each fix round; live-server exercise of all three stable codes, the admin-skip fix, and confirmation that the pre-existing email-uniqueness path still reports correctly.
