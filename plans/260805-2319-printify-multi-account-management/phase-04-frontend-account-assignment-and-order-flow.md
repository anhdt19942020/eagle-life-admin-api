---
phase: 4
title: "Frontend account assignment and order flow"
status: completed
priority: P1
effort: "8-10h"
dependencies: [2, 3]
---

# Phase 4: Frontend account assignment and order flow

## Overview

Add the admin-only Printify Accounts page, expose account/shop assignment in user create/edit forms, and remove seller/leader shop selection from order creation. Admin retains the existing picker and shop operations.

## Context links

- `D:/Projects/eagle-life-admin-fe/src/router/index.js:1-70`
- `D:/Projects/eagle-life-admin-fe/src/components/common/AppSidebar.vue:75-91`
- `D:/Projects/eagle-life-admin-fe/src/views/printify/PrintifyShopListView.vue`
- `D:/Projects/eagle-life-admin-fe/src/views/orders/OrderListView.vue`
- `D:/Projects/eagle-life-admin-fe/src/components/printify/PrintifyShopPicker.vue`
- `D:/Projects/eagle-life-admin-fe/src/views/users/UserListView.vue`
- `D:/Projects/eagle-life-admin-fe/src/views/users/UserDetailView.vue`
- `D:/Projects/eagle-life-admin-fe/src/services/printifyService.js`
- `D:/Projects/eagle-life-admin-fe/src/services/orderService.js`
- `D:/Projects/eagle-life-admin-fe/design.md`

## Key insights

- Sidebar visibility is permission-based, but the current router only guards authentication. Add a route permission guard for direct navigation; API authorization remains authoritative.
- `OrderListView` currently loads all selectable shops and sends `shop_id` for single/bulk creation. That request is appropriate only for admin after the API contract changes.
- User list/detail forms already react to role and sales-group changes. Printify selectors should follow the same pattern: account changes clear the shop, and seller/leader roles make both assignment fields required.
- FE has no test script. The primary verification is `npm run build`; behavioral coverage is owned by API feature tests.

## Requirements

- Functional: admin sees a separate Printify Accounts menu/page with email, status, key-configured state, shop/user counts, edit, deactivate, and activate actions.
- Functional: account forms accept an API key as password input but never populate or display an existing key.
- Functional: admin user create/edit forms select account first, then one unassigned active shop belonging to that account.
- Functional: seller/leader order modal shows only the assigned shop name and does not render `PrintifyShopPicker`.
- Functional: admin order modal continues to render the picker and sends the selected shop.
- Functional: missing assignment shows a clear warning/disabled action when known, while backend error handling remains the final guard.
- Functional: the admin user list can filter seller/leader users by assignment state (assigned / unassigned), and the shop list shows which user currently holds each shop. Without this, the Phase 5 rollout step "assign every seller and leader" and the reserved-assignment rule for deactivated users are both unexecutable — an admin would have to open users one at a time to find gaps, and a departed employee's shop looks free when it is not.
- Functional: `group_leader` no longer sees a shop-sync action (the permission is admin-only now) and shop readiness actions appear only for that leader's own shop.
- Functional: <!-- Updated: Validation Session 1 --> admin shop sync **must** require selecting a Printify account before calling `POST /printify/shops/sync` with `account_id` (no global sync, no implicit account 1). If the current shop list UI cannot do this, add the control in this phase.
- Functional: <!-- Updated: Validation Session 1 --> seller/leader order create/preview **must not** send `shop_id`; UI shows assigned shop title only. Backend will `422` if a spoofed `shop_id` is still sent.
- UX: follow `design.md`: compact workbench, existing Base components, no raw secret, no new color system.

## Architecture

```text
auth.user.printifyShop → seller/leader order read-only shop label
printifyAccountService → admin account list/form
printifyService.listShops({ account_id }) → dependent user-form shop options
orderService.createDraft(id, optionalShopId)
  ├─ admin: { shop_id }
  └─ seller/leader: no shop_id
```

The UI may disable create when the authenticated user has no assignment, but it must not assume local storage is authoritative. Refresh `/me` after login and after assignment changes where needed.

## File inventory

| Action | File | Test/build impact |
|---|---|---|
| Create | `src/services/printifyAccountService.js` | Account CRUD/status API wrapper |
| Create | `src/views/printify/PrintifyAccountListView.vue` | Admin account management UI |
| Modify | `src/services/printifyService.js` | Account filter and scoped sync helpers |
| Modify | `src/services/orderService.js` | Optional shop payload for admin only |
| Modify | `src/views/orders/OrderListView.vue` | Role-aware picker/read-only assignment/single + bulk flow |
| Modify | `src/views/printify/PrintifyShopListView.vue` | Account filter and selected-account sync |
| Modify | `src/views/users/UserListView.vue` | Account/shop assignment fields and dependent loading |
| Modify | `src/views/users/UserDetailView.vue` | Account/shop assignment fields and dependent loading |
| Modify | `src/router/index.js` | Account route and permission guard metadata |
| Modify | `src/components/common/AppSidebar.vue` | Admin-only account menu item |
| Modify if needed | `src/stores/auth.js` | Safe assignment computed state/refresh behavior |
| Preserve | `src/components/printify/PrintifyShopPicker.vue` | Admin-only reuse; no seller access |

## Implementation Steps

1. Add `printifyAccountService` methods matching the API contract: list/create/update/activate/deactivate.
2. Build the account page with explicit write-only API key UX: create requires it; edit has an empty replacement field and a `has_api_key` indicator; never bind a returned key.
3. Add `/printify/accounts` route metadata and sidebar permission `printify.accounts.view`; add direct route denial/redirect for non-authorized users.
4. Extend `printifyService` to list shops by account and sync a selected account (`account_id` required). <!-- Updated: Validation Session 1 --> Update shop management UI with an explicit account selector (or sync only for the filtered account) before sync; show assigned holder per shop; hide sync for non-admins; hide readiness actions on shops the caller does not own. **No global sync button.**
5. Add an assignment-state filter (assigned / unassigned) to the user list for seller and group-leader rows so gaps are visible at a glance.
6. Extend user list/detail forms with account then shop selectors. Load only active accounts/shops, reset shop when account/role changes, preserve current assignment on edit, and surface the API's duplicate/unassigned validation errors.
7. Update `OrderListView`: use the auth user's assigned shop for seller/leader; render its title in a non-editable field; remove all shop-list loading for those roles. Keep picker, selected shop state, and bulk behavior for admin.
8. Update `orderService.createDraft` to include `{ shop_id }` only when an admin selection exists. <!-- Updated: Validation Session 1 --> Seller/leader payloads omit `shop_id` entirely (API rejects spoof). Ensure single and bulk calls use the same role-aware path.
9. Run the production build, inspect direct URL/permission behavior, and smoke-test account → user → order → account-scoped sync flows against the API.

## Test scenario matrix

| Scenario | Expected result | Verification owner |
|---|---|---|
| Admin opens Accounts menu | Menu/page visible and loads safe fields | FE build + manual smoke |
| Non-admin opens `/printify/accounts` directly | Route denied or redirected; API would still return `403` | FE manual smoke + API test |
| Edit account with blank key | Existing key remains; no key appears in form/network response | Manual + API test |
| User form account changes | Shop selection clears and reloads for that account | Manual smoke |
| Same shop already assigned | API error shown; UI does not silently overwrite | API test + manual |
| Seller order modal | Assigned shop title only; no picker/all-shop request; **no `shop_id` in payload** | Manual smoke/build inspection |
| Seller without assignment | Warning/disabled create; server error remains understandable | API test + manual |
| Admin order modal | Picker remains and selected shop is sent for single/bulk | Existing order flow smoke |
| Admin sync shops | Must select account (or sync filtered account); request includes `account_id`; no global sync | Manual smoke |
| Account deactivated | UI status changes; assigned user UI remains readable; order API blocks | API test + manual |

## Function/interface checklist

- [ ] No account API key is stored in reactive state after response parsing.
- [ ] Account/shop selectors are dependent and role-aware.
- [ ] Seller/leader never call the all-shop picker endpoint just to create an order.
- [ ] Seller/leader create/preview requests never include `shop_id`. <!-- Updated: Validation Session 1 -->
- [ ] Admin shop sync always sends `account_id`; no global sync control. <!-- Updated: Validation Session 1 -->
- [ ] Admin single and bulk creation still pass the selected local shop ID.
- [ ] `PrintifyShopPicker` is not removed if admin still needs it.
- [ ] Sidebar and route guards use the same permission string as the API seeder.
- [ ] FE error handling branches on `response.data.data.code` (`printify_shop_assignment_required`, `printify_account_inactive`, `printify_shop_not_ready`), never on the Vietnamese `message` string.

## Dependency map

- Phase 2 defines account/user response fields and permissions.
- Phase 3 defines the order/shop/sync request behavior that this UI consumes.
- Phase 4 must be deployed after API phases; it can be rolled back to the old UI because the server still resolves non-admin assignment.
- Phase 5 validates API contracts and runs the FE build/smoke checklist.

## Success Criteria

- [ ] Admin-only Printify Accounts page is reachable and fully manages status/key rotation without key disclosure.
- [ ] User create/edit supports exactly one unique assignment for seller/leader.
- [ ] Seller/leader order modal shows assigned shop name and no selector; payloads omit `shop_id`.
- [ ] Admin shop sync requires an account selection and sends `account_id`.
- [ ] Admin order flow retains shop selection for both single and bulk creation.
- [ ] `npm run build` passes in `D:/Projects/eagle-life-admin-fe`.

## Risk Assessment

- Local storage may contain stale user assignment after an admin changes it. Mitigation: refresh `/me` on order view entry or after user changes; always rely on API enforcement.
- A UI-only hidden selector can be bypassed. Mitigation: API rejects spoofed `shop_id` with `422` and has spoofing tests. <!-- Updated: Validation Session 1 -->
- Deploying FE before API would send unsupported requests. Mitigation: API-first rollout; old UI sync without `account_id` will fail closed until this phase ships. <!-- Updated: Validation Session 1 -->
