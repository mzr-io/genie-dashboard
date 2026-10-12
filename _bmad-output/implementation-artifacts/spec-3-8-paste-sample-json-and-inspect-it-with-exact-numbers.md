---
title: 'Paste sample JSON and inspect it with exact numbers'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '4a8a8290798b9eb905558132db4538b7983adfda'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-6-accept-only-json-losslessly-and-within-limits.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-7-choose-the-data-source-endpoint-parameters-and-refresh-step.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-5-autosave-continuously-and-recover-from-expiry-offline-and-save-failure.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Step 2 has no sample response, so an Admin cannot see a response's structure, paths, types and exact numbers before an API is reachable.

**Approach:** Implement the paste half of Story 3.8 (epics.md "### Story 3.8"; its Given/When/Then ACs are authoritative and are the I/O matrix): Sample Response segmented control (Fetch/Paste; Fetch stays inert, 3.9), `json-textarea` validating on paste and blur, server-side validation by the platform's lossless JSON decoder with exact `DecimalLiteral` numbers, `msg:json-valid` / `msg:json-invalid` with Go to line, byte/depth limit rejection, storage only in `draft_samples` (encrypted with the workspace `data` key, paste origin, Draft-scoped, TTL from the existing `draft_sample_ttl` tunable, "contains personal data" acknowledgement), "Pasted 09:14" summary and `msg:sample-wizard` badge, a collapsible JSON tree with table view for arrays showing display path, inferred type and example per node, stored RFC 9535 subset paths (`$.data.monthly[*].revenue`) with a conformance test against `symfony/json-path`, an architecture test that `json_decode` is not used in Ingestion/RawStore/Mapping/Results, and the sample restorable after autosave/reopen and deleted on discard.

## Boundaries & Constraints

**Decisions (builder, from repo precedent; do not re-litigate):** (1) Reuse the existing lossless decoder and JSON limits from Story 2.6; if a limit tunable (`pending_input`) is unset, reject the paste with a reason naming that the limit is not configured — never invent a number; likewise an unset `draft_sample_ttl` means the sample cannot be stored (inline reason), mirroring the unset-means-inert convention. (2) Encrypt via the existing workspace `data`-key facility used for tokens/samples (config/dashflow.php notes a sample is never kept in the clear); no new migration unless the `draft_samples` table cannot hold the needed columns (it has source 'paste', body, size_bytes) — if one is needed add a forward migration, keep RLS. Body is the raw lossless text; the tree/paths are derived on read. (3) One sample per Draft: a new accepted paste replaces the previous one. (4) Replacing/accepting a sample sets nothing about `sample_out_of_date` beyond clearing it for the fresh paste (the 3.7 flag); Continue stays disabled while a pasted text is invalid. (5) Publish-time refusal of pasted samples is Story 3.18, not here. (6) "Contains personal data" acknowledgement is a required checkbox before the sample is accepted/stored; unticked → not stored, inline reason.

**Always:** Admin routes under the existing blocks permission with the Draft lock/CAS rules of 3.6 (a write needs the lock claim; reads allowed to a read-only viewer); workspace RLS; throttle; the sample body and its secrets-looking content never appear in logs, audit or errors (audit records at most size/origin); `RejectsSecretValues` must not block a sample body (route-scoped like `lock_token`); sample restorable on reopen (read endpoint) and part of the autosave/pending story only as server state (no body in sessionStorage); reuse labels `json-valid`, `json-invalid`, `sample-wizard`; labels added to the catalogue; accessible announcements (polite live region).

**Never:** No Fetch-from-API, field roles or mapping (3.9+), no `json_decode` in the named modules, no invented limits/TTL, no raw-tier or version storage of the sample, no change to Data Source forms.

## I/O & Edge-Case Matrix

Story 3.8's Given/When/Then ACs in `_bmad-output/planning-artifacts/epics.md` are the matrix; every one must be covered by a passing test, plus: unset limit/TTL → inline reason and nothing stored; foreign-workspace Draft → 404; write without lock claim → 423; discard deletes the sample; second paste replaces the first.

</frozen-after-approval>

## Code Map

- Storage: `database/migrations/2026_10_17_100000_create_blocks_and_drafts.php` (`draft_samples`), `PurgeDiscardedDrafts.php`, `config/dashflow.php` (`draft_sample_ttl` ~75, data key notes ~190)
- Lossless JSON: `LosslessJson`, `DecimalLiteral`, Story 2.6 limits (find via `app/Platform`/Connector `FetchResponse`, `DirectFetchTransport`), `Jcs.php`
- Blocks: `ManageDrafts`, `DraftController`, `routes/api.php` (drafts routes; lock middleware pattern from 3.6; `RejectsSecretValues:blocks`), `ShellNavigation::ADMIN_API_ROUTES`, `tests/Architecture/*`
- Client: `components/wizard/DataSourceStep.vue`, `lib/blockSource.ts`, `BlockWizard.vue`, existing `json-textarea`/segmented-control components (grep `ui/`), `locales/{en,labels}.ts`
- Paths: `symfony/json-path` (in composer.json) for the conformance suite
- Tests: `tests/Database/BlockSourceStepTest.php`, `tests/js/block-wizard-step2.test.ts` patterns; architecture tests

## Tasks & Acceptance

**Execution:**
- [x] server: validate/store/read/delete-on-discard pasted sample (encrypted, replace-on-new, TTL, acknowledgement), structure+paths derivation, limits, throttle, permission/route mapping, architecture test, path conformance suite
- [x] client: segmented control, json-textarea with validation/Go to line, summary + badge, tree/table view, restore on reopen, labels
- [x] Tests: Database + Vitest covering every Story 3.8 AC and the extra rows

**Acceptance Criteria:**
- Given each Story 3.8 scenario, when exercised, then the outcome holds.
- Given the full suite, when `bin/tools composer ci:check` runs, then it passes.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| TrimStrings/ConvertEmptyStrings altered pasted text | medium | patch | sample routes exempted; round-trip tests added |
| Continue not blocked for unchecked/stale/failed paste | medium | patch | sampleBlock covers all states; tests added |
| Huge draft_sample_ttl could 500 on store | low | patch | ceiling treats it as not configured; test added |
| Vault binding, corruption and lowered-limit reads unasserted | medium | patch | Database tests added |
| Unique index on legacy duplicate rows | false | rejected | no code wrote draft_samples before this story |
| Node-count cap, legacy null expires_at purge, key rotation leaves sample unopenable (503), restore/accept race, 409/404 mapping on store, no audit event, store decodes under row lock, expiry not shown, i18n string split for Go to line | low/maybe-false | defer | recorded in deferred-work.md |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
