---
title: "Printify multi-account codebase and research report"
type: report
status: complete
created: 2026-08-05
---

# Printify multi-account codebase and research report

## Summary

The API currently uses one process-wide `PRINTIFY_TOKEN`; shops, syncs, and order creation are not account-scoped. The frontend has a Printify shop management page and a shop picker in the order modal. The selected design keeps one canonical shop assignment per seller/group leader, scopes every Printify request through the shop's account, and keeps API keys write-only to the UI.

## Codebase findings

| Area | Evidence | Planning impact |
|---|---|---|
| Client credential | `app/Services/Printify/PrintifyClient.php:10-49` reads `config('services.printify.token')` inside every request | Add an account-scoped token seam; do not keep a mutable global token |
| Shop persistence | `app/Models/PrintifyShop.php:9-58`; migrations `2026_08_04_000006` plus later readiness migrations | Add `printify_account_id`; preserve readiness state and the existing globally unique remote shop ID |
| Shop sync | `app/Services/Printify/PrintifySyncService.php:14-205` globally deactivates missing shops and uses `printify:sync:account` | Scope deactivation/upsert/locks to the selected account; update scheduled commands |
| Order trust boundary | `app/Http/Controllers/Api/PrintifyOrderController.php:18-70` requires and resolves request `shop_id` | Resolve seller/leader shop from authenticated user; admin may select; never trust a non-admin selection |
| User assignment | `app/Http/Controllers/Api/UserController.php:13-172`, `app/Models/User.php:30-62` already enforce role/sales-group rules | Extend the same store/update flow with one nullable unique shop FK |
| Permissions | `database/seeders/RolePermissionSeeder.php:13-83`; seller currently lacks `printify.order.create` | Add account permissions for admin and grant order-create to seller |
| Auth context | `app/Http/Controllers/Api/AuthController.php:35-53` returns `/me` and login user data | Include safe assigned-shop metadata; never eager-load a serializable API key |
| Frontend order flow | `src/views/orders/OrderListView.vue`, `src/components/printify/PrintifyShopPicker.vue`, `src/services/orderService.js` | Keep picker for admin; show assigned shop read-only for seller/leader and omit `shop_id` |
| Frontend navigation | `src/router/index.js`, `src/components/common/AppSidebar.vue:75-91` | Add admin-only Printify Accounts route/menu item |
| Frontend test/build | `package.json` has only `dev`, `build`, `preview` | Use `npm run build`; no FE unit-test runner is configured |

## Confirmed decisions

- Seller and group leader each have exactly one assigned shop.
- A shop cannot be assigned to more than one seller/group leader. Enforce with a nullable unique database index plus request validation.
- Admin manages accounts and may choose any shop when creating an order on behalf of an operation.
- Deactivate is reversible; no hard-delete endpoint.
- API keys are never returned or revealed in the UI. Backend decrypts only when constructing an outbound Printify request.
- Existing shops are bootstrapped to account id 1 from the current environment token. The one-time bootstrap also requires the legacy account email through `PRINTIFY_ACCOUNT_EMAIL` or an explicit command option.
- Printify's official API documentation states shop IDs are unique across the Printify system, so the existing global unique `printify_shop_id` constraint can remain while adding account ownership.

## Research findings

1. Laravel's `encrypted` Eloquent cast stores encrypted values in the database, requires a `TEXT`-sized column, prevents database searching on the encrypted value, and requires deliberate re-encryption during `APP_KEY` rotation: [Laravel encrypted casting](https://laravel.com/docs/11.x/eloquent-mutators#encrypted-casting).
2. Laravel's `Crypt::encryptString` / `decryptString` uses authenticated encryption and raises a decrypt exception for tampered values: [Laravel encryption](https://laravel.com/docs/11.x/encryption).
3. Laravel supports scoped `Rule::exists(...)->where(...)` validation, which is suitable for checking that a selected shop belongs to the selected account: [Laravel validation](https://laravel.com/docs/11.x/validation#available-validation-rules).
4. Printify documents that `GET /v1/shops.json` lists shops for the authenticated merchant account and that each shop ID is unique across the Printify system: [Printify API Reference](https://developers.printify.com/API-Doc-RREdits.html/1000).

## Scope boundary

Included: account CRUD/status, encrypted credential storage, legacy bootstrap, shop ownership, unique user assignment, account-scoped sync/client/order creation, permissions, API contracts, frontend account/user/order UI, tests, docs, and rollout.

Deferred: failover/round-robin routing, health checks, automatic account switching, multiple shops per user, shared shop assignments, raw key reveal, and Printify OAuth.

## Risks to carry into implementation

- `APP_KEY` must remain stable after encrypted credentials are stored; rotate only with an explicit re-encryption procedure.
- API and frontend must deploy in that order. New frontend depends on assignment fields and account-scoped API behavior.
- Existing scheduled Printify commands must be changed together with the client; leaving one global-token command would reintroduce cross-account behavior.
- Existing users will remain unassigned until an admin assigns a shop; no reliable automatic mapping is available.
