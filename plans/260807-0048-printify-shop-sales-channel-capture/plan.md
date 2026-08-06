---
title: "Printify shop sales channel capture"
description: "Persist and expose the sales_channel that Printify already returns for every shop, so eBay, TikTok, and unconnected shops are distinguishable in the shop list."
status: pending
priority: P2
effort: "1-2h"
branch: "main"
tags: [feature, backend, api, printify]
blockedBy: []
blocks: []
created: 2026-08-07
---

# Printify shop sales channel capture

## Overview

`GET /v1/shops.json` returns three fields per shop — `id`, `title`, `sales_channel` — but `PrintifySyncService::syncShops()` maps only `id` and `title`. The marketplace of every shop is therefore discarded at sync time, and nothing downstream can tell an eBay shop from a TikTok shop.

This plan persists `sales_channel` verbatim and exposes it on the shop resource. Nothing consumes it as a filter yet; the goal is to stop throwing the data away.

## Evidence

Probed against the live account configured in `.env` (read-only `GET /shops.json`, HTTP 200):

| Fact | Value |
|---|---|
| Shops returned | 393 |
| Keys per shop | `id`, `title`, `sales_channel` |
| `sales_channel` = `ebay` | 374 shops |
| `sales_channel` = `tiktok` | 4 shops |
| `sales_channel` = `disconnected` | 15 shops |

Two observations that drive the design:

- Values are lowercase slugs that already match `SalesGroup::PLATFORMS` (`ebay`, `tiktok`, `amazon`) — no mapping table is needed. `amazon` was not observed because this account has no Amazon shop connected; the value is unverified.
- `title` is not a channel signal. Shop `1880108` is titled "My Ebay" but reports `disconnected`. Any UI or logic that infers the marketplace from the shop name is wrong.

Printify's docs define only `disconnected` explicitly ("the name of the associated sales channel; if none are connected it defaults to `disconnected`") and do not enumerate the rest — so the vocabulary is owned by Printify and may grow.

## Scope

| In scope | Out of scope |
|---|---|
| `sales_channel` column on `printify_shops` | Filtering the shop list by channel |
| Capturing it in `PrintifySyncService::syncShops()` | Any link between `sales_channel` and `sales_groups.platform` |
| Exposing it on `PrintifyShopResource` | Frontend badge/column in `eagle-life-admin-fe` |
| Feature tests + `API_DOCS.md` §6.3 | Backfill script, enum/whitelist validation, index |

## Contract

- Column `printify_shops.sales_channel`: nullable string, no default, no index.
- Sync writes whatever Printify returns, unmodified; a missing key writes `null`.
- `GET /api/printify/shops` items gain `sales_channel` (`string|null`) after `title`.
- Additive only — no existing field, response key, or status code changes.

## Decisions

| Decision | Rationale |
|---|---|
| Store the raw Printify string, no enum or whitelist | Printify owns the vocabulary. A whitelist would silently null out a channel Printify adds later (e.g. `amazon` the first time an Amazon shop is connected). |
| Nullable, not `NOT NULL DEFAULT 'disconnected'` | `null` means "this row has not been synced since the column existed"; `'disconnected'` means "Printify says nothing is connected". Collapsing them hides whether a rollout finished. |
| No backfill migration | `syncShops()` uses `updateOrCreate` on `printify_shop_id`, so the next sync fills all 393 rows. A backfill would duplicate the sync's own job. |
| No index | Nothing queries by channel yet. Add one with the first filter that needs it. |
| Value read as `$shop['sales_channel'] ?? null` | The docs promise the field, but one absent key would otherwise fatal the whole 393-shop transaction. `title` stays a direct access because the sync already treats it as required. |

## Architecture

```text
Printify GET /shops.json
  → [{id, title, sales_channel}]
  → PrintifySyncService::syncShops()   # add one attribute to the existing map
  → PrintifyShop::updateOrCreate(['printify_shop_id' => id], [... sales_channel])
  → PrintifyShopResource                # emit after title
  → GET /api/printify/shops
```

No new service, job, or migration path — the change is one attribute threaded through an existing pipeline.

## Goals

| # | Goal | Priority |
|---|------|----------|
| 1 | Stop discarding `sales_channel` at sync time | P1 |
| 2 | Expose the channel on the shop list API | P1 |
| 3 | Lock both behaviours with tests and document the verified values | P2 |

## Phases

| # | Phase | Status |
|---|-------|--------|
| 1 | [Persist and expose sales channel](./phase-01-start.md) | Pending |
| 2 | [Tests docs and verification](./phase-02-tests-docs-and-verification.md) | Pending |

## Dependencies

- Builds on `260805-2319-printify-multi-account-management` (shop sync + account ownership) and `260806-2255-printify-shop-readiness-sync-and-status-ui` (current resource shape) — both completed, neither blocking.
- No dependency on the in-flight order soft-delete work; different tables and files.

## Success Criteria

- [ ] A sync run persists the exact `sales_channel` string Printify returned for each shop.
- [ ] A shop whose payload omits `sales_channel` is stored with `null` and does not abort the sync.
- [ ] `GET /api/printify/shops` returns `sales_channel` on every item.
- [ ] Existing Printify feature tests still pass; no existing response key changed.
- [ ] `API_DOCS.md` §6.3 lists the field and the observed values, and states that `title` does not imply the channel.

<!-- slug: printify-shop-sales-channel-capture -->
