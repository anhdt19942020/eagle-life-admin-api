---
phase: 2
title: "Tests docs and verification"
status: pending
priority: P2
effort: "30-45m"
dependencies: [1]
---

# Phase 2: Tests docs and verification

## Overview

Lock the sync-persistence and API-exposure behaviours with feature tests, and document the field with the values verified against the live account.

## Requirements

- Functional: a test proves the synced `sales_channel` reaches the database on both create and update.
- Functional: a test proves a payload missing the key yields `null` without aborting the sync.
- Functional: a test proves `GET /api/printify/shops` exposes the field.
- Functional: `API_DOCS.md` §6.3 documents the field, its observed values, and the `title` caveat.
- Non-functional: the whole Printify feature suite still passes.

## Architecture

Tests follow the existing Printify pattern: `DatabaseMigrations` + `InteractsWithPrintifyAccounts`, `configurePrintifyHttpBase()`, then `Http::fake(['printify.test/v1/shops.json' => ...])`, driving `SyncPrintifyShopsJob` directly with `Bus::fake([EnsurePrintifyAccountDefaultSkusJob::class])`.

Test placement follows what each file already owns:

- `PrintifyShopSyncApiTest` owns sync-to-database behaviour → the persistence and null cases.
- `PrintifyShopOpenTest` owns the index response shape → the exposure case.

The existing `test_sync_job_upserts_by_printify_shop_id_without_duplicates` already fakes a two-shop payload covering both the update (101) and create (202) paths. Extend that fake with `sales_channel` and assert it, rather than adding a third near-duplicate sync test.

## Related Code Files

- Modify: `tests/Feature/PrintifyShopSyncApiTest.php`
- Modify: `tests/Feature/PrintifyShopOpenTest.php`
- Modify: `API_DOCS.md` (§6.3 "Danh sách Shop")

## Implementation Steps

1. In `test_sync_job_upserts_by_printify_shop_id_without_duplicates`, add `'sales_channel' => 'ebay'` to shop 101 and `'sales_channel' => 'tiktok'` to shop 202 in the fake, then assert both values in the existing `assertDatabaseHas` calls.
2. Add a test that fakes one shop with no `sales_channel` key and asserts the row persists with `sales_channel` `null`.
3. In `PrintifyShopOpenTest`, create a shop with a known `sales_channel` and assert the index item carries it.
4. Update `API_DOCS.md` §6.3: add `sales_channel` to the item field list, state the observed values (`ebay`, `tiktok`, `disconnected`), note `null` means not yet synced since the field was added, and warn that `title` does not imply the channel.
5. Run `php artisan test --filter=Printify`, then the full suite.

## Todo

- [ ] Persistence asserted on both create and update paths.
- [ ] Missing-key case asserted.
- [ ] Index exposure asserted.
- [ ] `API_DOCS.md` §6.3 updated.
- [ ] Printify suite green, then full suite green.

## Success Criteria

- New assertions fail if `sales_channel` is dropped anywhere in the sync or resource path.
- No pre-existing test needed modification beyond the extended fake payload.
- `php artisan test` passes with no new failures.
- §6.3 states the observed values and the `title` caveat.

## Risk Assessment

- Asserting an exact channel vocabulary in tests would make a legitimate new Printify value look like a regression. Tests assert pass-through of whatever string was faked, never a whitelist.
- Documenting `ebay | tiktok | disconnected` as if exhaustive would mislead the frontend. §6.3 must say these are the values observed on one account, not a closed set — `amazon` is expected but unverified.
