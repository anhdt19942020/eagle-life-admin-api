---
phase: 1
title: "Document policy matrix in API_DOCS"
status: done
---

# Phase 1: Document policy matrix in API_DOCS

## Overview

Turn the existing one-line notes under §5.1 / §5.2 into an explicit duplicate/re-import policy matrix so FE and operators do not assume one endpoint behaves like the other.

## Requirements

- Functional: Add a short subsection under Import (e.g. §5.0 or after §5.2) titled duplicate / re-import policy.
- Functional: Matrix covers JSON skip vs CSV upsert, keys, response fields (`failed`/`errors` vs `created`/`updated`), and HTTP status.
- Non-functional: Preserve existing wording elsewhere or replace with a pointer to the matrix (avoid contradictory notes).

## Related Code Files

- Modify: `API_DOCS.md` (section 5 only)
- Reference only: `app/Services/OrderImportService.php` (`importFromArray`, `persistCsvOrder`, `upsertLineItem`)

## Implementation Steps

1. Insert policy matrix documenting:
   - JSON: empty/`exists` `ebay_order_id` → fail that item, continue batch, HTTP 200.
   - CSV: same `Order Number` → update order + upsert line items; batch item `outcome` = `created`|`updated`.
   - CSV line identity: `Transaction ID` preferred; else fallback hash of Item Number / Custom Label / Variation / Sold For.
2. Move or reduce the existing one-liners at §5.1 / §5.2 to link at the new matrix.
3. Cite source symbols / test method names in a small “Evidence” note under the matrix.

## Todo

- [x] Write duplicate-policy matrix in `API_DOCS.md`
- [x] Align §5.1 / §5.2 notes with the matrix (no contradiction)
- [x] Cite code + test evidence

## Success Criteria

- [x] A reader can answer “what if I import the same eBay order twice?” for both endpoints without reading PHP
- [x] Docs do not claim unified behavior across JSON and CSV

## Risk Assessment

- Risk: Readers assume FE still calls JSON path — mitigate by noting production FE uses `/orders/import-csv`.
