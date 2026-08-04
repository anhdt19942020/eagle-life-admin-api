---
phase: 2
title: "Cover raw persist with import tests"
status: pending
priority: P1
effort: "30m"
dependencies: [1]
---

# Phase 2: Cover raw persist with import tests

## Overview

Extend `EbayCsvImportTest` so regression covers raw dump + buyer fields using Seller Hub-shaped headers.

## Requirements

- Functional: Assert `ebay_export_rows` contains expected keys/values from import CSV.
- Functional: Assert `ebay_raw` on line item and buyer fields on order.
- Functional: Re-import updates raw payloads (overwrite), still idempotent on line count.

## Related Code Files

- Modify: `tests/Feature/EbayCsvImportTest.php`

## Implementation Steps

1. Add a test CSV fixture string that includes at least `Buyer Username`, `Buyer Name`, `Buyer Email`, and one uncommon column (e.g. `Variation Details` or `Sold Via Promoted Listings`).
2. Assert order buyer fields + `ebay_export_rows[0]['Buyer Email']` (or equivalent).
3. Assert line `ebay_raw['Item Number']` / `Variation Details`.
4. Optionally re-import with a changed optional column and assert overwrite.

## Todo

- [x] New test for raw + buyer persistence
- [x] Re-import overwrite assertion
- [x] Run `EbayCsvImportTest` green

## Success Criteria

- [x] New assertions fail before Phase 1 wire-up and pass after
- [x] Existing import tests remain green
