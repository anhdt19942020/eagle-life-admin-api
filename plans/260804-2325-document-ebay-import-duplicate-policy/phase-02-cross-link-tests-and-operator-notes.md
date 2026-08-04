---
phase: 2
title: "Cross-link tests and operator notes"
status: done
priority: P2
effort: "20m"
dependencies: [1]
---

# Phase 2: Cross-link tests and operator notes

## Overview

Make the documented policy discoverable from this plan and from test entry points, still without changing runtime code.

## Requirements

- Functional: Plan success criteria list the exact test methods that lock the policy.
- Functional: Add a brief operator note in `API_DOCS.md` (how to re-import eBay Sold CSV safely; what JSON import will not do).
- Non-goals: New tests, refactors, renaming methods.

## Related Code Files

- Modify: `API_DOCS.md` (operator note + evidence links)
- Modify: this plan’s `plan.md` success criteria / evidence table if needed
- Reference only:
  - `tests/Feature/EbayImportHttpTest.php` → `test_json_import_counts_duplicate_as_failed`
  - `tests/Feature/EbayCsvImportTest.php` → idempotent fallback + re-import overwrite cases

## Implementation Steps

1. Under the policy matrix, add “Verified by” bullets naming the feature tests above.
2. Add 3–5 operator bullets: prefer CSV for eBay Seller Hub re-exports; JSON is create-only for new ids; re-import CSV refreshes address/line raw payloads; does not delete missing line items unless documented otherwise (state what code actually does: upsert only).
3. Confirm `OrderImportService` does not delete orphaned line items on re-import; document that gap as known behavior (docs-only).

## Todo

- [x] Link tests under the matrix
- [x] Operator re-import notes
- [x] Document known non-behavior (no line-item prune on re-import) if still true

## Success Criteria

- [x] Policy matrix points to at least one JSON + one CSV test
- [x] Operator can choose the correct endpoint for “file already imported once”

## Risk Assessment

- Risk: over-documenting future intent — stick to “as implemented” language only.
