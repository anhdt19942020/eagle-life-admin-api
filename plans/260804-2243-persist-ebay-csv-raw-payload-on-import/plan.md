---
title: "Persist eBay CSV raw payload on import"
description: "Wire OrderImportService so eBay Seller Hub CSV import stores full row payloads and buyer identity fields already present on the schema."
status: completed
priority: P1
effort: "1h"
tags: [ebay, import, orders]
created: 2026-08-04
---

# Persist eBay CSV raw payload on import

## Outcome

After CSV import, each order keeps the full eBay export rows so no Seller Hub column is lost, and buyer identity columns are denormalized for quick access.

## Contract

| Field | Value |
|-------|--------|
| Outcome | Import stores `orders.ebay_export_rows`, `orders.ebay_buyer_*`, and `order_line_items.ebay_raw` from eBay CSV |
| Constraints | Reuse migration `2026_08_04_000013`; no new schema; do not map tax/fee/date columns to typed fields in this delivery |
| Non-goals | Order list/show API expansion; Printify create; country-code normalization; money/ship/tracking typed mapping |
| Acceptance | Re-import of a real-shaped CSV asserts non-empty raw payloads containing key headers (e.g. `Order Number`, `Buyer Email`, `Variation Details`) |

## Goals

| # | Goal | Priority |
|---|------|----------|
| 1 | Persist all CSV columns for an order into `ebay_export_rows` | P1 |
| 2 | Persist per-line CSV row into `ebay_raw` | P1 |
| 3 | Persist `Buyer Username` / `Buyer Name` / `Buyer Email` onto order | P1 |
| 4 | Cover with feature tests using Seller Hub column set | P1 |

## Evidence (pre-plan)

- Sample file `Bota001 Dlinh.csv`: UTF-8 BOM, blank lead row, **82** Seller Hub columns, 9 order lines.
- Schema + model fillable/casts already exist (`000013`, `Order`, `OrderLineItem`).
- `OrderImportService::persistCsvOrder` / `upsertLineItem` currently omit raw + buyer fields.

## Chosen approach

Minimal wire-up in `OrderImportService` only: strip `_row`, save order-level rows + buyer fields, save line-level raw. Leave typed money/ship columns untouched (YAGNI vs selected AC).

## Risks

- Large multi-line orders grow JSON size — acceptable for admin import scale.
- `Ship To Country` = `United States` vs `US` remains a separate fulfillment risk (out of scope).

## Phases

| # | Phase | Status |
|---|-------|--------|
| 1 | [Wire raw CSV payload on import](./phase-01-start.md) | Done |
| 2 | [Cover raw persist with import tests](./phase-02-cover-raw-persist-with-import-tests.md) | Done |

## Success Criteria

- [x] Import writes `ebay_export_rows` with every source column present on the CSV row (except `_row`)
- [x] Import writes `ebay_raw` per line item
- [x] Import writes buyer username/name/email when present
- [x] Tests prove persistence and re-import overwrite behavior (`EbayCsvImportTest` 7/7)

<!-- slug: persist-ebay-csv-raw-payload-on-import -->
