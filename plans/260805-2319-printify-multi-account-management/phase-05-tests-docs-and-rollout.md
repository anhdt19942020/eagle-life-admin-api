---
phase: 5
title: "Tests docs and rollout"
status: pending
priority: P1
effort: "6-8h"
dependencies: [1, 2, 3, 4]
---

# Phase 5: Tests docs and rollout

## Overview

Protect the new security and assignment contracts with API feature tests, update operator/API documentation, run both repositories' verification commands, and define a safe API-first production rollout with a reversible application rollback.

## Context links

- `tests/Feature/PrintifyAuthorizationTest.php`
- `tests/Feature/PrintifyClientTest.php`
- `tests/Feature/PrintifyShopSyncApiTest.php`
- `tests/Feature/PrintifySyncTest.php`
- `tests/Feature/PrintifyOrderPreviewTest.php`
- `tests/Feature/PrintifyOrderCreateTest.php`
- `API_DOCS.md:525-620`
- `.env.example:64-70`
- `D:/Projects/eagle-life-admin-fe/package.json`
- `D:/Projects/eagle-life-admin-fe/docs/deployment.md`

## Key insights

- API uses PHPUnit feature tests with `DatabaseMigrations`, SQLite in-memory, Sanctum, Spatie permissions, and `Http::fake`.
- Existing Printify tests assume one config token and request `shop_id`; they must be updated to create accounts/assign users rather than weakening the new security contract.
- Ten Printify test files exist today. The authoritative list of files that set `services.printify.token` is `grep -rn "services.printify.token" tests/` — run it before starting and after Phase 3 so no token-dependent test is missed. As of planning it matches `PrintifyClientTest`, `PrintifyOrderCreateTest`, `PrintifyShopOpenTest`, `PrintifyShopSyncApiTest`, and `PrintifySyncTest`.
- Frontend has no unit/e2e test script, so production build plus a manual smoke matrix is the honest verification boundary.

## Requirements

- Functional: tests prove credential isolation, account/shop sync isolation, unique assignment, seller/leader resolver behavior, admin selection, status transitions, and no remote call on preflight failure.
- Functional: API docs describe account endpoints, user assignment fields, error codes, write-only key behavior, and bootstrap/deploy order.
- Non-functional: `php artisan test`, Pint/static checks, and FE `npm run build` pass before handoff.
- Security: test responses and test artifacts contain no actual credentials; use deterministic fake tokens only.
- Rollout: preserve the legacy env token until account 1 bootstrap and smoke verification complete.

## Architecture

```text
tests → API contract/security gate → docs/rollout checklist → API deploy → bootstrap → FE deploy → smoke test
```

Application rollback means reverting API/FE code while retaining the additive account/assignment schema and data. Do not drop encrypted account data as part of a normal rollback.

## File inventory

| Action | File | Purpose |
|---|---|---|
| Create | `tests/Feature/PrintifyAccountApiTest.php` | CRUD/status/credential secrecy/permissions |
| Create | `tests/Feature/PrintifyLegacyBootstrapTest.php` | Idempotent account-1 bootstrap and missing-input behavior |
| Create | `tests/Feature/PrintifyUserAssignmentTest.php` | Required, scoped, unique assignment and role transitions |
| Modify | `tests/Feature/PrintifyAuthorizationTest.php` | Account permissions, seller order permission, admin-only sync, and the cross-account ownership guard on every `{shop}` route |
| Modify | `tests/Feature/PrintifyClientTest.php` | Account-specific bearer tokens and retry behavior |
| Modify | `tests/Feature/PrintifyShopSyncApiTest.php` | Account-scoped endpoint/sync |
| Modify | `tests/Feature/PrintifySyncTest.php` | Account/shop lock and sync isolation |
| Modify | `tests/Feature/EnsurePrintifyShopDefaultSkuTest.php` | Account-aware default-SKU product seed |
| Modify | `tests/Feature/PrintifyOrderPreviewTest.php` | Assignment preflight and admin/non-admin selection |
| Modify | `tests/Feature/PrintifyOrderCreateTest.php` | Account client, spoofing, missing assignment, idempotency |
| Modify | `tests/Feature/PrintifyShopOpenTest.php` | Sets `services.printify.token` at `:101` and `:138`; must switch to an account fixture once the runtime fallback is removed |
| Modify | `tests/Feature/PrintifyShopDefaultSkuTest.php` | Default-SKU gate now runs through an account-scoped client |
| Modify | `tests/Feature/PrintifySelectionApiTest.php` | Shop/account catalog scoping if contract changes |
| Modify | `API_DOCS.md` | Account/assignment/order contract and errors |
| Modify | `.env.example` | Legacy bootstrap email/token window and key warning |
| Modify if needed | `docs/deployment.md` | API-first deployment/bootstrap checklist if API docs are insufficient |

## Test scenario matrix

| Risk | Test | Required assertion |
|---|---|---|
| Credential cross-use | two account clients | Authorization headers are different and correct |
| Secret disclosure | account/user/login/shop responses | No `api_key`, plaintext fake token, or nested credential field |
| Account isolation | sync A with shops A+B | Only A state changes; mismatch is rejected |
| Assignment sharing | assign same shop twice | Second request fails and DB remains unchanged |
| Assignment spoofing | seller sends shop B while assigned A | A is used or request rejected; B never receives HTTP |
| Cross-account shop mutation | leader opens/closes/re-SKUs a shop it does not own | `403`; target row unchanged; zero outbound requests |
| Cross-account enumeration | leader lists shops and products | Only the assigned shop appears; other accounts' shops absent |
| Sync privilege | leader calls `POST /printify/shops/sync` | `403`; permission is admin-only |
| Listing query cost | admin lists shops and users with account metadata | Query count stays bounded as row count grows (eager loads present) |
| Audit attribution | rotate a key, change an assignment | `key_rotated_by/at` and `printify_shop_assigned_by/at` are populated |
| Missing assignment | seller/leader has null FK | Stable 422 code; zero outbound requests |
| Inactive account | assigned shop's account inactive | Stable preflight error; zero outbound requests |
| Admin path | admin chooses shop B | B is used and existing picker contract works |
| Key rotation | update with empty/new key | Empty retains; new replaces encrypted value; neither returns raw |
| Status | deactivate/activate | Assignments and shops remain; calls blocked/restored as designed |
| Legacy migration | bootstrap twice | Account id 1 and shop links remain singular/idempotent |

## Implementation Steps

1. Add test factories/helpers that create an account with a deterministic fake key, an owned shop, a ready shop state, and users for admin/seller/group leader without sharing test fixtures across cases.
2. Write/adjust API tests for account CRUD/status, permission boundaries, response allowlists, encrypted storage, and key rotation.
3. Add bootstrap command tests for account id 1, shop backfill, rerun, `--force`, and missing token/email without printing the token.
4. Update existing client/sync/order tests to use explicit accounts and assignments; retain assertions for retry, readiness, payload mapping, conflicts, and idempotency.
5. Add API docs with request/response examples that use fake placeholders only. Document that `api_key` is input-only and that seller/leader omit `shop_id`.
6. Document `PRINTIFY_ACCOUNT_EMAIL`, stable `APP_KEY`, bootstrap command, API-first deploy order, account-1/shop-count verification, and legacy-token removal.
7. Run targeted API tests first, then full API tests and Pint. Run FE `npm run build` from the sibling repository.
8. Perform manual smoke tests: admin account create/key rotation, sync account 1, assign one shop to seller/leader, seller order create, unassigned error, deactivate/reactivate, admin picker.

## Verification commands

```text
API:
grep -rn "services.printify.token" tests/   # must return no unconverted test
php artisan test tests/Feature/PrintifyAccountApiTest.php tests/Feature/PrintifyLegacyBootstrapTest.php tests/Feature/PrintifyUserAssignmentTest.php tests/Feature/PrintifyClientTest.php tests/Feature/PrintifyShopSyncApiTest.php tests/Feature/PrintifySyncTest.php tests/Feature/PrintifyShopOpenTest.php tests/Feature/PrintifyShopDefaultSkuTest.php tests/Feature/PrintifyOrderPreviewTest.php tests/Feature/PrintifyOrderCreateTest.php
vendor/bin/pint --test
php artisan test

FE:
npm run build
```

## Function/interface checklist

- [ ] Every changed API response has an allowlist assertion for secret absence.
- [ ] Every changed caller in the contract inventory has a test or an explicit manual smoke check.
- [ ] API docs match the final route names, payloads, status codes, and stable error codes.
- [ ] Bootstrap and deploy instructions do not print or commit secrets.
- [ ] FE build is run from the sibling repo, not assumed from API verification.

## Dependency map

- Phases 1–4 must be complete before final tests can represent the final contract.
- Test results gate production rollout.
- Documentation must be updated from the final API routes, not from an earlier draft.
- Deployment is API-first, then bootstrap, then FE; rollback keeps schema/data.

## Success Criteria

- [ ] Targeted API tests pass.
- [ ] Full API test suite passes.
- [ ] Pint/static checks pass for changed PHP.
- [ ] FE production build passes.
- [ ] API docs and env/deployment instructions are updated without secrets.
- [ ] Manual smoke matrix passes for admin, seller, leader, unassigned, inactive, and reactivated flows.

## Rollout and rollback

1. Back up the production database and verify `APP_KEY` is stable.
2. Set `PRINTIFY_ACCOUNT_EMAIL` and retain the current `PRINTIFY_TOKEN`; deploy API migrations/code first.
3. Run `php artisan migrate` and `php artisan printify:bootstrap-account --email=...`; verify account id 1, encrypted key presence, and shop count.
4. Seed permissions and sync account 1. Count the unassigned users that will be blocked (`select count(*) from users u join model_has_roles ... where u.printify_shop_id is null` for roles `seller` and `group_leader`), assign each one a shop, then re-check that the count is zero before smoke-testing preview/create.
5. Deploy FE, verify admin account menu and seller/leader read-only shop UI.
6. After verification, remove the legacy token from normal runtime configuration; keep only the documented bootstrap procedure if operationally required.
7. If application rollback is needed, revert API/FE code but retain additive schema/account data. Do not drop or decrypt/rewrite credentials during rollback. Re-run the bootstrap only after confirming the intended account-1 key source.

## Risk Assessment

- A passing FE build does not prove role behavior. Mitigation: run the API permission/assignment tests and the manual smoke matrix.
- Removing the legacy token too early can make scheduled sync fail. Mitigation: count/check account 1 and perform one successful scoped sync before removal.
- Production migration rollback can destroy encrypted credentials if treated as a normal down migration. Mitigation: rollback application code only; require an explicit data-migration decision for schema reversal.
