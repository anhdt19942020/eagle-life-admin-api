---
phase: 1
title: "Wire raw CSV payload on import"
status: done
priority: P1
effort: "30m"
dependencies: []
---

# Phase 1: Wire raw CSV payload on import

## Overview

Update `OrderImportService` so CSV persist fills existing JSON/buyer columns without schema changes.

## Requirements

- Functional: On create/update from CSV, store all grouped rows on the order and the source row on each line item.
- Functional: Map `Buyer Username`, `Buyer Name`, `Buyer Email` onto order columns.
- Non-functional: Keep existing validation/required headers unchanged.
- Non-goal: Do not start filling `shipping_amount` / `total_amount` / tracking in this phase.

## Architecture

```
CSV rows (group by Order Number)
  -> sanitizeRow(row) drop `_row`
  -> Order.ebay_export_rows = [sanitized...]
  -> Order.ebay_buyer_* from first row
  -> per line group: OrderLineItem.ebay_raw = sanitized(lineRow)
```

## Related Code Files

- Modify: `app/Services/OrderImportService.php`

## Implementation Steps

1. Add a private helper that copies a CSV associative row and unsets `_row`.
2. In `persistCsvOrder`, after loading the order, `fill` buyer fields + `ebay_export_rows` from all grouped rows, then `save`.
3. In `upsertLineItem`, include `ebay_raw` from the sanitized representative row.
4. Keep address/line identity logic unchanged.

## Todo

- [x] Helper `sanitizeCsvPayload(array $row): array`
- [x] Persist `ebay_export_rows` + buyer fields in `persistCsvOrder`
- [x] Persist `ebay_raw` in `upsertLineItem`

## Success Criteria

- [x] Code path writes the three payload families on import/update
- [x] No migration changes
