---
title: "Document eBay import duplicate policy"
description: "Document the existing JSON-skip vs CSV-upsert duplicate rules for eBay order import without changing runtime behavior."
status: completed
priority: P2
effort: "45m"
tags: [ebay, import, orders, docs]
created: 2026-08-04
---

# Document eBay import duplicate policy

## Outcome

Operators and FE/API consumers have one clear place describing what happens when an eBay order is imported again — without changing code.

## Contract

| Field | Value |
|-------|--------|
| Outcome | `API_DOCS.md` documents the two current duplicate policies; plans/tests are cross-linked |
| Constraints | Docs only — no changes to `OrderImportService`, routes, schema, or FE |
| Non-goals | Unify JSON/CSV behavior; change skip↔update; add new endpoints |
| Acceptance | Section 5 states a matrix; each rule cites existing code + tests |

## Current policy (as implemented)

| Path | Duplicate key | Behavior | Evidence |
|------|---------------|----------|----------|
| `POST /orders/import` (JSON) | `ebay_order_id` | Skip row → `failed++`, message in `errors`, HTTP 200 | `OrderImportService::importFromArray`; `EbayImportHttpTest::test_json_import_counts_duplicate_as_failed` |
| `POST /orders/import-csv` | `Order Number` → `ebay_order_number` | Upsert order → outcome `created`/`updated`; line items idempotent by `Transaction ID` or fallback identity | `persistCsvOrder` / `upsertLineItem`; `EbayCsvImportTest` re-import + idempotent cases |

## Goals

| # | Goal | Priority |
|---|------|----------|
| 1 | Add an explicit duplicate-policy subsection under Import (API_DOCS §5) | P1 |
| 2 | Cross-link tests / operator notes so re-import expectations are discoverable | P2 |

## Phases

| # | Phase | Status |
|---|-------|--------|
| 1 | [Document policy matrix in API_DOCS](./phase-01-start.md) | Done |
| 2 | [Cross-link tests and operator notes](./phase-02-cross-link-tests-and-operator-notes.md) | Done |

## Success Criteria

- [x] API_DOCS has a dedicated duplicate/re-import matrix for JSON vs CSV
- [x] Docs cite code entry points and existing feature tests
- [x] No application PHP/JS behavior changed

<!-- slug: document-ebay-import-duplicate-policy -->
