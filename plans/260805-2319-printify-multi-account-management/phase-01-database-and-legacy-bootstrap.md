---
phase: 1
title: "Database and legacy bootstrap"
status: completed
priority: P1
effort: "5-6h"
dependencies: []
---

# Phase 1: Database and legacy bootstrap

## Overview

Create the persistent account/assignment model without changing runtime order behavior yet. Add a repeatable bootstrap command that encrypts the current environment token into account id 1 and attaches every existing shop to that account.

## Context links

- `app/Models/PrintifyShop.php:9-58`
- `app/Models/User.php:30-62`
- `database/migrations/2026_08_04_000006_create_printify_shops_table.php`
- `database/migrations/2026_08_05_000015_create_sales_groups_and_add_user_sales_group.php`
- `config/services.php:34-42`
- [Laravel encrypted casting](https://laravel.com/docs/11.x/eloquent-mutators#encrypted-casting)
- [Codebase and research report](./reports/codebase-and-research.md)

## Key insights

- The current remote shop ID has a global unique index. Printify documents that shop IDs are unique across the system, so retain that index and add account ownership separately.
- Existing users and shops may already exist when the migration runs. New FKs must be nullable during deployment; the bootstrap command establishes the operational non-null invariant before account-scoped code is enabled.
- `api_key` must be a `TEXT` column and hidden from model serialization. The encrypted cast depends on the stable application `APP_KEY`.

## Requirements

- Functional: account id 1 stores the current token and legacy account email; all existing shops receive `printify_account_id = 1`.
- Functional: each user has at most one nullable local shop FK; the non-null value is unique across users.
- Functional: account deactivation is represented by a reversible `is_active` flag, not a delete.
- Non-functional: migration is SQLite/MySQL compatible with the existing test setup.
- Security: the bootstrap command never prints or stores the plaintext token outside the encrypted model attribute.

## Architecture

```text
printify_accounts
  id, email(unique), api_key(TEXT encrypted), is_active, timestamps
  key_rotated_by(nullable FK users), key_rotated_at(nullable)

printify_shops
  ...existing fields..., printify_account_id(nullable FK)
  existing unique(printify_shop_id) remains

users
  ...existing fields..., printify_shop_id(nullable FK, unique)
  printify_shop_assigned_by(nullable FK users), printify_shop_assigned_at(nullable)
```

The account FK on shops is nullable only to make the deploy/migration safe. The bootstrap command must succeed before Phase 2/3 production traffic is switched to DB-backed accounts. Existing unassigned users remain nullable and are handled by the API assignment guard.

## File inventory

| Action | File | Test impact |
|---|---|---|
| Create | `database/migrations/2026_08_05_000016_create_printify_accounts_table.php` | Account table, encrypted `TEXT` field, unique email |
| Create | `database/migrations/2026_08_05_000017_add_printify_account_and_user_shop_assignments.php` | Nullable FKs and unique user assignment |
| Create | `app/Models/PrintifyAccount.php` | Encrypted cast, hidden key, relationships |
| Create | `app/Console/Commands/BootstrapLegacyPrintifyAccount.php` | Idempotent account-1 bootstrap and shop backfill |
| Create | `app/Console/Commands/ReencryptPrintifyAccountKeys.php` | `printify:reencrypt-keys` — decrypt with the old key and re-encrypt with the current one during an `APP_KEY` rotation |
| Modify | `app/Models/PrintifyShop.php` | Fillable account FK and `account()` relation |
| Modify | `app/Models/User.php` | Fillable assignment and `printifyShop()` relation |
| Modify | `config/services.php` | Add one-time `account_email` bootstrap config |
| Modify | `.env.example` | Document `PRINTIFY_ACCOUNT_EMAIL` without a secret value |
| Verify later | `tests/Feature/PrintifyLegacyBootstrapTest.php` | Owned by Phase 5 to avoid test-file overlap |

## Implementation Steps

1. Add the account table with `email`, encrypted `api_key`, `is_active` defaulting true, timestamps, a unique email index, and nullable `key_rotated_by`/`key_rotated_at` audit columns. Credential and assignment changes must be attributable; the codebase already uses this pattern in `manual_approval_confirmed_by`/`_at` on shops, so follow it rather than inventing a separate audit log.
2. Add nullable `printify_account_id` to shops with a foreign key and keep the existing remote-ID unique index.
3. Add nullable unique `users.printify_shop_id` with `nullOnDelete`, plus nullable `printify_shop_assigned_by`/`printify_shop_assigned_at`; do not assign users automatically.
4. Add model relationships/casts/hidden fields. Ensure `PrintifyAccount::toArray()` and any eager-loaded user response cannot expose `api_key`.
5. Implement `printify:bootstrap-account` with an explicit email option falling back to `PRINTIFY_ACCOUNT_EMAIL`, the current configured token, a transaction, and idempotent account id 1 upsert/backfill. If account id 1 already has a key, require an explicit `--force` before replacing it. Abort with a clear message if either required legacy input is missing; never include the token in the message.
6. Implement `printify:reencrypt-keys`, which reads the previous key from `APP_PREVIOUS_KEYS`, decrypts each stored `api_key`, and re-writes it under the current `APP_KEY` inside a transaction. It must be runnable before the old key is dropped from `APP_PREVIOUS_KEYS`, print only account ids and a count, and be idempotent when values already decrypt under the current key.
7. Add config/example documentation and a deployment note that the bootstrap runs after migration while the legacy token is still available.
8. Run migration rollback/reapply checks on SQLite and inspect the schema/indexes before dependent phases.

## Test scenario matrix

| Scenario | Expected result | Verification owner |
|---|---|---|
| Fresh migration | Tables/FKs/indexes exist | Phase 5 migration test |
| Existing shops + bootstrap | Account id 1 created; every existing shop gets account id 1 | Phase 5 command test |
| Bootstrap rerun | No duplicate account or shop assignment | Phase 5 command test |
| Missing token/email | Command fails without leaking credential | Phase 5 command test |
| Two users assign same shop | Database rejects duplicate non-null assignment | Phase 5 assignment test |
| Account deactivate/activate | Flag changes without deleting account or shops | Phase 5 account test |
| API key serialization | Response/model array does not contain plaintext key | Phase 5 account test |

## Function/interface checklist

- [x] `PrintifyAccount` exposes `shops()` and safe credential state only.
- [x] `PrintifyShop` exposes `account()`.
- [x] `User` exposes `printifyShop()`.
- [x] Bootstrap command is idempotent and has no plaintext-token output path.
- [x] Migration down order removes user FK/index before shop/account tables.

## Dependency map

- Phase 1 → Phase 2: account model, shop ownership, and user FK must exist before validation/API resources.
- Phase 1 → Phase 3: account id 1 backfill and account credential storage are prerequisites for scoped clients.
- Phase 1 → Phase 4: `/me` and user forms depend on the assignment field, but UI work starts after API contracts are available.

## Success Criteria

- [x] Migrations apply and roll back on the project SQLite test database.
- [x] Account API key is encrypted at rest and absent from serialization.
- [x] Bootstrap creates account id 1 and links all existing shops without duplicate rows.
- [x] The unique user-shop index prevents shared assignments.
- [x] No application order request behavior is changed in this phase.

## Risk Assessment

- `APP_KEY` rotation can make stored keys undecryptable. Mitigation: `printify:reencrypt-keys` (created in this phase) must run while the old key is still in `APP_PREVIOUS_KEYS`; document that dropping the previous key before that run permanently orphans every stored credential and forces manual re-entry of every account key.
- A partially completed deploy may leave nullable `printify_account_id`. Mitigation: make the bootstrap command idempotent and block Phase 3 rollout until an account/shop count check passes.
- Re-running bootstrap with a different legacy token can rotate account 1 unexpectedly. Mitigation: print a non-secret confirmation summary and require an explicit `--force` option for replacing an existing account key.

## Implementation record

- Code-reviewer subagent (2026-08-05) found 3 Critical + 1 Medium issue in the first pass, all fixed and re-verified before this phase was marked complete:
  1. `printify:reencrypt-keys` was a no-op — Eloquent's `originalIsEquivalent()` decrypts both sides of an `encrypted`-cast attribute before comparing, so a same-plaintext reassignment is never dirty and `save()` silently skips the UPDATE. Fixed by writing the freshly re-encrypted ciphertext through the query builder directly, bypassing the dirty-check. Verified empirically on a real MySQL instance: ciphertext hash changes on each run, decrypt still returns the correct plaintext afterward.
  2. Migration `017`'s `down()` dropped the unique index on `users.printify_shop_id` before its FK constraint — fine on SQLite, but MySQL/InnoDB reuses that index as the FK's supporting index and refuses the drop (error 1553) while the FK is live. This project's actual `.env` driver is MySQL, not SQLite (only tests use SQLite). Fixed by reordering to `dropForeign` → `dropUnique` → `dropColumn`. Verified by starting a local MySQL instance, running migrate → rollback → reapply against an isolated throwaway database.
  3. `BootstrapLegacyPrintifyAccount` could leak the plaintext token into console/log output: `QueryException::formatMessage()` interpolates bound values (including the token) into its message text, and the save path had no try/catch. Fixed by wrapping the save transaction and never surfacing `$e->getMessage()`; also wrapped the pre-save decrypt-and-compare in a `DecryptException` catch with a clear, non-secret message.
  4. (Medium) The command reset `is_active => true` on every rerun, which would silently reactivate a deliberately deactivated account 1 once Phase 2 ships deactivation. Fixed to only set `is_active` on initial creation.
- Verification performed: fresh migrate/rollback/reapply and full bootstrap/reencrypt scenario matrix on SQLite; the same on a real local MySQL instance (isolated throwaway database, dropped after verification) specifically to validate finding #2, which SQLite could not have caught. Full test suite (82/82) and Pint clean on every touched file after each fix round.
