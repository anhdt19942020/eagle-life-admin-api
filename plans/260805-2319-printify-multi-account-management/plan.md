---
title: "Printify multi-account management and user shop assignment"
description: "Replace the single Printify token with encrypted accounts, unique seller/leader shop assignments, and account-scoped order creation."
status: pending
priority: P1
effort: "3-4d"
branch: "main"
tags: [feature, backend, frontend, database, api, auth, critical]
blockedBy: []
blocks: []
created: "2026-08-05T16:19:53.231Z"
createdBy: "ck:plan"
source: skill
---

# Printify multi-account management and user shop assignment

## Overview

Replace the hardcoded `PRINTIFY_TOKEN` flow with admin-managed Printify accounts (email + encrypted API key). Each seller and group leader receives one unique shop assignment; order creation resolves that assignment server-side, while admins retain shop selection.

The plan covers both repositories:

- API: `D:/Projects/eagle-life-admin-api`
- FE: `D:/Projects/eagle-life-admin-fe`

## Confirmed contract

- Account management is admin-only.
- Account status supports deactivate and activate; hard delete is not exposed.
- API keys are write-only to the UI. The backend decrypts them only for outbound Printify calls.
- Existing shops are assigned to account id 1 by a one-time bootstrap using the current `PRINTIFY_TOKEN` and `PRINTIFY_ACCOUNT_EMAIL`.
- A seller or group leader must have exactly one shop; a shop cannot be assigned to two seller/leader users.
- **Assignment is per user, not per sales group.** A group leader and the sellers inside that leader's group each hold a distinct shop, so orders originating from one sales group are deliberately spread across several Printify shops. This is intentional and is the reason `users.printify_shop_id` — not `sales_groups` — is the canonical assignment. If shop-per-group is ever wanted instead, it is a schema change, not a configuration change.
- Existing seller/group-leader users predate this feature and start with a `NULL` assignment. They are not backfilled automatically; until an admin assigns a shop they receive the stable `printify_shop_assignment_required` error. Rollout must count and assign these users before enabling seller order creation.
- Seller and group leader can create Printify orders. Their order UI shows the assigned shop name and removes the shop picker. <!-- Updated: Validation Session 1 --> Seller/leader requests must not send `shop_id`; if a spoofed `shop_id` is present and differs from the assignment, the API returns `422` and never uses that shop.
- Backend blocks missing assignments and **rejects** non-admin shop spoofing (`422` when `shop_id` differs from assignment) before any Printify request.
- **Non-admin Printify routes are scoped to the caller's own shop (decision, flip if wrong).** Under a single account, `group_leader` holding `printify.sync`, `printify.catalog.view`, and `printify.shop-readiness.confirm` on every shop was acceptable. With multiple accounts those same permissions become cross-tenant. The resolution adopted here:
  - `printify.sync` (account-wide shop-list sync, now takes `account_id`) becomes **admin-only** — a leader has no legitimate reason to resync another account's shop catalog.
  - `printify.shop-readiness.confirm` (open/close/confirm-approval/default-SKU) stays with `group_leader` but is **guarded by ownership**: a non-admin may only act on `auth()->user()->printify_shop_id`.
  - `printify.catalog.view` shop/product listing is **filtered to the caller's assigned shop** for non-admins; admins see everything.

  This preserves the leader's day-to-day workflow on their own shop while closing the cross-account boundary. The alternative — moving all four permissions to admin-only — is simpler but removes leader self-service; choose it instead if leaders should not manage shop readiness at all.
- No failover, round-robin, health-check, OAuth, or multi-shop-per-user behavior is included.

## Architecture

```text
PrintifyAccount (encrypted api_key, active)
        1 ──────── * PrintifyShop (account_id, remote id)
                              0..1 ───── User (seller/leader assignment)

seller/leader order request → authenticated user's shop → shop account → scoped client → Printify
admin order request         → validated selected shop → shop account → scoped client → Printify
```

The canonical user assignment is `users.printify_shop_id`; the account is derived from the shop so account/shop pairs cannot drift. A nullable unique index prevents sharing while allowing admins and unassigned users to remain outside the assignment.

## Phases

| Phase | Name | Status |
|-------|------|--------|
| 1 | [Database and legacy bootstrap](./phase-01-database-and-legacy-bootstrap.md) | Completed |
| 2 | [Account and user assignment API](./phase-02-account-and-user-assignment-api.md) | Completed |
| 3 | [Account-scoped Printify integration](./phase-03-account-scoped-printify-integration.md) | Completed |
| 4 | [Frontend account assignment and order flow](./phase-04-frontend-account-assignment-and-order-flow.md) | Pending |
| 5 | [Tests docs and rollout](./phase-05-tests-docs-and-rollout.md) | Pending |

## Dependencies

- No unfinished plan blocks this work; the existing eBay and sales-group plans are completed.
- Stable Laravel `APP_KEY` is required before storing encrypted API keys.
- Deployment must keep legacy `PRINTIFY_TOKEN` and set `PRINTIFY_ACCOUNT_EMAIL` until the account-1 bootstrap succeeds.
- API phase must be deployed before the frontend phase; the old frontend remains a compatible rollback client because the backend resolves non-admin assignments server-side.
- Research and codebase inventory: [codebase-and-research.md](./reports/codebase-and-research.md).

## Success criteria

- [ ] Admin can create, edit, deactivate, and reactivate accounts without seeing an existing API key.
- [ ] Existing shops are linked to account 1 and future syncs never deactivate another account's shops.
- [ ] Seller/leader assignment is required, account-consistent, and unique per shop.
- [ ] Seller/leader order preview/create uses only the assigned shop and returns a stable assignment error when absent.
- [ ] Seller has `printify.order.create`; account management remains admin-only.
- [ ] API feature tests, PHP formatting/static checks, and FE production build pass.
- [ ] `API_DOCS.md`, `.env.example`, and deployment steps document the new contract without secrets.

## Plan verification log

- Scope challenge: HOLD. Existing Printify services/UI are reused; failover, round-robin, health checks, OAuth, shared shops, and multi-shop users remain out of scope.
- Codebase fact check: client, sync, order controller/services, scheduled commands, user/auth APIs, permission seeder, FE order/user/shop/sidebar/router consumers were inspected. `EnsurePrintifyShopDefaultSku.php` is included because it calls account-sensitive product sync.
- Research: Laravel encryption/authorization/validation and Printify shop-ID scope are recorded in [codebase-and-research.md](./reports/codebase-and-research.md).
- CLI validation: `ck plan validate plans/260805-2319-printify-multi-account-management/plan.md --strict` → PASS, 0 errors, 0 warnings.
- Red-team fallback: `ck plan red-team` is unavailable in CLI 4.5.0. Manual adversarial review covered secret serialization, cross-account sync/locks, assignment spoofing/races, stale frontend payloads, scheduled callers, migration rollback, and API-first rollout. No unresolved contradiction remains.
- Depth review (2026-08-05, against code at `e44a6d0`): six gaps found and patched into the phases — (1) Phase 3 named no injection mechanism although `PrintifySyncService.php:14` and `PrintifyOrderCreateService.php:15` hold a constructor-injected readonly `PrintifyClient`; a `PrintifyClientFactory` is now specified and the effort raised to 10-13h. (2) Phase 5 omitted `PrintifyShopOpenTest.php` and `PrintifyShopDefaultSkuTest.php`, both of which break when the token fallback is removed. (3) The `APP_KEY` mitigation referenced a re-encryption run that no phase created; `printify:reencrypt-keys` is now a Phase 1 artifact. (4) Per-user vs per-sales-group assignment was never stated; recorded above as an intentional decision. (5) Printify rate limits are per account — sequential scheduled iteration is now specified. (6) The stable error code had no envelope; it is now pinned to `data.code` to match `App\Traits\ApiResponse`.
- Multi-persona pre-analysis (`/ak:predict`, 2026-08-05): verdict **CAUTION**, no STOP trigger. One blocking finding — the plan preserved `printify.sync` and `printify.shop-readiness.confirm` for `group_leader` (`RolePermissionSeeder.php:73-79`, `routes/api.php:46-52`) while `PrintifyShopController::index` applies no user scoping, so a leader could sync, open, close, re-SKU, or enumerate another account's shops. Security enforcement had been designed for the order routes only, leaving five of six non-admin Printify routes cross-tenant. Resolved by the ownership decision recorded in the contract above plus a single `PrintifyShopPolicy`. Three secondary findings also patched: N+1 on shop/user listings once account metadata is added, missing assignment-visibility UI that made the rollout step unexecutable, and no audit attribution for key rotation or assignment changes.
- Whole-plan consistency sweep: all plan/phase/report files reread after the manual review; confirmed one canonical `users.printify_shop_id`, unique non-null assignment, reversible account status, no raw key response, account-1 bootstrap, and API-first deployment order.

## Validation Log

### Session 1 — 2026-08-06
**Trigger:** `/ak:plan --validate` before Phase 3 cook  
**CLI format:** `ak plan validate` → valid  
**Questions asked:** 6 (BA-facing)

#### Verification Results
- **Tier:** Full (5 phases)
- **Claims checked:** ~82
- **Verified:** 48 | **Failed:** 19 | **Unverified:** 6 | **Stale plan≠code:** 9
- **Key failures:** callers still call account-less `syncShops()`; no `PrintifyShopPolicy`; order still request-`shop_id` for all roles; silent cross-account skip in shop sync; shop lock not account-keyed
- **Stale:** factory/`PrintifyClientFactory` + account-scoped sync/order-create services already in tree; `printify.sync` already admin-only in seeder

#### Questions & Answers

1. **[Scope]** Lần này hoàn thiện phần còn thiếu (không làm lại phần đã có) hay làm lại từ đầu?
   - Options: A hoàn thiện phần còn thiếu | B làm lại từ đầu | Other
   - **Answer:** A
   - **Rationale:** Factory/services đã có — cook tập trung wiring, policy, resolver, commands, tests

2. **[Risk]** Sync tài khoản A gặp shop đã thuộc tài khoản B — dừng báo lỗi hay bỏ qua?
   - Options: A dừng + báo lỗi | B bỏ qua + ghi nhận | Other
   - **Answer:** A
   - **Rationale:** Không đụng dữ liệu tài khoản khác; khớp product-sync path đã throw

3. **[UX/Security]** Seller/leader gửi kèm shop_id (FE cũ) — xử lý thế nào?
   - Options: A BE bỏ qua shop_id | B BE từ chối nếu lệch | Other
   - **Answer:** Other — **Sửa cả FE**; nếu UI thiếu thì ghi vào plan. BE vẫn enforce assignment; nếu `shop_id` gửi lên khác shop đã gán → `422` (không dùng shop spoof). FE Phase 4 phải bỏ picker/`shop_id` cho seller/leader.
   - **Rationale:** Không chỉ dựa vào “BE im lặng bỏ qua”; FE và BE cùng đúng hợp đồng

4. **[Rollout]** Sync bắt buộc chọn tài khoản vs mặc định tài khoản 1 khi FE cũ chưa kịp?
   - Options: A bắt buộc | B default account 1 | Other
   - **Answer:** Other — **Làm cả hai phía**: API `account_id` required **và** UI chọn tài khoản trước sync phải có trong Phase 4 (bổ sung nếu thiếu). Không dựa vào default account 1.
   - **Rationale:** Tránh half-compat; FE sync theo account là deliverable rõ

5. **[Scope]** Kiểm thử lần cook này — tối thiểu hay đủ bộ Printify?
   - Options: A tối thiểu + Phase 5 full | B full Printify tests ngay | Other
   - **Answer:** B
   - **Rationale:** Phase 3 owns rewrite/update of integration tests that break under account-scoped client; Phase 5 vẫn docs/rollout + remaining account/bootstrap/assignment suites

6. **[Risk]** Khóa sync theo tài khoản+shop hay chỉ theo shop ID?
   - Options: A account+shop | B shop ID only | Other
   - **Answer:** A
   - **Rationale:** An toàn hơn khi multi-account; khớp checklist Phase 3

#### Confirmed Decisions
- Phase 3 rebaseline: finish wiring — do not re-implement factory/core services
- Cross-account shop sync mismatch → fail/abort (no silent skip)
- Dual-side order contract: FE removes seller/leader shop selection; BE rejects spoofed `shop_id`
- Sync: API requires `account_id`; FE must provide account-scoped sync UI (Phase 4)
- Phase 3 includes full Printify integration test updates (not defer-all to Phase 5)
- Shop sync locks keyed by account + remote shop id

#### Action Items
- [x] Propagate decisions into phase-03 / phase-04 / phase-05
- [ ] On cook: change silent `reject` in `PrintifySyncService` to throw
- [ ] On cook: order resolver + spoof `422`; Phase 4 FE order payload
- [ ] On cook: sync endpoint `account_id` required + Phase 4 shop-list sync UI
- [ ] On cook: lock key `printify:sync:shop:{accountId}:{remoteId}`
- [ ] On cook: rewrite broken Printify feature tests in Phase 3 scope

#### Impact on Phases
- Phase 3: rebaseline overview/steps; fail-safely = throw; hard spoof reject; own integration tests; account-keyed shop locks
- Phase 4: elevate account-scoped sync UI + seller never sends `shop_id` as hard requirements (not optional)
- Phase 5: keep account/bootstrap/assignment/docs/rollout; Phase 3 absorbs integration test rewrite ownership note

### Whole-Plan Consistency Sweep
- Files reread: `plan.md`, `phase-01`…`phase-05`
- Decision deltas checked: 6 (rebaseline wiring-only; cross-account abort; dual-side FE+BE spoof/`shop_id`; sync `account_id` + FE UI; Phase 3 owns integration tests; account+shop locks)
- Reconciled stale references: Phase 3 steps/matrix/risks; Phase 4 requirements/steps/checklist/success; Phase 5 test ownership; plan confirmed-contract spoof wording
- Unresolved contradictions: 0
- Note: Phase 3 architecture diagram still says `PrintifyClient::forAccount` while code uses `PrintifyClientFactory::for` — cosmetic naming only; implementation follows factory (already in tree)
