# Username Required Broke User Create, and We Couldn't Even See Why

**Date**: 2026-08-06 21:08
**Severity**: Medium
**Component**: `POST /api/users` (API) + Users/Login/SalesGroups/PrintifyAccounts forms (eagle-life-admin-fe)
**Status**: Resolved

## What Happened

Commit `68e8052` ("authenticate and manage users by username") made `username` a required field on `POST /api/users` so login could switch from email to username. Nobody updated the frontend create-user form, which only ever sent `name`. Every admin trying to create a user got a 422 with **no visible error at all** — the modal just sat there silently failing.

That second half turned out to be its own bug: `normalizeApiError` was reading `error.errors` for the field bag, but this API wraps Laravel's validation errors under `data` (e.g. `data.username: ["The username field is required."]`), not a top-level `errors` key. So even once we knew the 422 was happening, the UI had no way to surface *why*.

## The Brutal Truth

Two independent bugs stacked on top of each other, and each one hid the other. The missing `username` field would have thrown a normal, debuggable validation error — except the FE error handler was looking in the wrong place, so it just swallowed it. Meanwhile a regression test (`test_create_user_without_username_is_rejected`) already asserted the correct API shape (`data.username.0`) and would have caught the FE contract mismatch immediately if anyone had cross-checked it against the frontend when `68e8052` shipped. The API and FE repos drifted for one commit and an admin-facing form silently broke.

## Technical Details

- API response shape on validation failure: `{ "data": { "username": ["The username field is required."] }, ... }` — confirmed via `tests/Feature/UserRoleGroupValidationTest.php::test_create_user_without_username_is_rejected`, which also asserts `assertJsonMissingPath('errors')`.
- FE bug: `src/utils/apiError.js` only checked `error.errors`, which is always `undefined` for this API, so `normalizeApiError` never populated field-level `details`.
- Business-error edge case: some non-validation errors put a stable string `code` on `data` (e.g. `{ data: { code: "SOME_ERROR" } }`). A naive "treat any object under `data` as a field bag" fix would have misread `code` as a validation message for a field literally named `code`. Guarded with `if (typeof error.data?.code === "string") return null;` in the new `formErrorsFromApi` helper.

## What We Tried

Nothing exotic — this was a straightforward two-part fix once both root causes were identified:
1. Added `username` inputs to the create/edit user forms so the request actually satisfies the API contract.
2. Extracted a shared `formErrorsFromApi(error)` helper (`src/utils/formErrorsFromApi.js`) that checks `error.errors` first (back-compat) then falls back to `error.data`, with the `code`-is-a-string guard to avoid misclassifying business errors as field errors. Wired it into `normalizeApiError` in `apiError.js`.
3. Rewired every view that manually parsed validation errors (UserList, UserDetail, Login, SalesGroups, PrintifyAccounts) to use the shared helper instead of duplicated ad-hoc `error.errors` reads.

## Root Cause Analysis

1. **API/FE contract drift**: a backend field became required without a corresponding FE change in the same PR/review pass. No contract test or FE smoke test caught it before merge.
2. **Wrong assumption baked into shared error-handling code**: `apiError.js` assumed the generic Laravel `errors` envelope, but this API's actual convention (documented, just not read carefully) puts validation messages under `data`. That assumption was never verified against the real API response and shipped into multiple views by copy-paste.

## Lessons Learned

- When a field becomes `required` on an existing write endpoint, grep the frontend for every call site of that endpoint as part of the same change — don't rely on the FE team noticing later.
- Centralize error-shape parsing in one helper instead of letting every view hand-roll `error.errors` checks; one wrong assumption in shared code is far cheaper to fix than the same wrong assumption duplicated five times.
- Silent failures (button does nothing, no toast, no console) are worse than loud ones — this bug shipped because the failure mode gave zero signal to the person testing it.
- Documented the actual error envelope in `API_DOCS.md` (§2.4: 422 → read field errors from `data`, not `errors`) so this doesn't get re-litigated next time.

## Next Steps

- [x] API: regression test added (`UserRoleGroupValidationTest::test_create_user_without_username_is_rejected`) — done, committed alongside `68e8052`.
- [x] API: `API_DOCS.md` §2.4 clarified to describe the `data`-based error envelope.
- [x] FE: `username` field added to create/edit user forms; `formErrorsFromApi` extracted and wired into `apiError.js`, `UserListView`, `UserDetailView`, `LoginView`, `SalesGroupListView`, `PrintifyAccountListView`.
- [ ] Consider a lightweight FE↔API contract check (e.g. shared OpenAPI/schema or a smoke test hitting real endpoints) so required-field changes fail loudly in CI instead of silently in the UI.

AgentWiki publish skipped (`ak` CLI not available in this environment) — entry kept as local journal file only.
