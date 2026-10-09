---
title: 'Configure Block (step 1) with a live preview'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '6fa79c136938b07a85c0574b2c474aba2300da12'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-3-start-save-and-discard-a-draft-block.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-2-see-the-kpi-card-in-every-state-rendered-safely-and-exactly.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The "Create block" page is a placeholder: an Admin cannot name a block, set its display and behaviour options, or see its size, and categories do not exist.

**Approach:** Build the five-step Create block wizard shell with Step 1 (Configure Block), persist step-1 fields in the Draft's lenient `config` (schema_version 1), add `block_categories` with a lazily seeded "General" category, and show a sticky live preview of the kpi-card skeleton with Desktop/Tablet/Mobile toggles.

## Boundaries & Constraints

**Always:** Replace the `admin.blocks.create` placeholder with `admin/BlockWizard` (keep route name and `blocks.edit` mapping); it opens a Draft from `?draft=<id>&step=<n>` or creates one on first Save/Continue, and needs a single-Draft GET (`api.admin.blocks.drafts.show`, `blocks.edit`). Header: "Block management / Create block", title, "• Draft" badge, Save draft, Preview, Publish block. Progress `nav` "Create block progress": ordered list of five steps (Configure Block, Data Source, Map Data, Preview, Save/Publish), current `aria-current="step"`, later steps plain text "(not started)", completed steps collapse to a summary card with check and "Edit", buttons named "{n}. {label}, completed|not completed"; jump back to completed steps, forward only to the first incomplete. Step 1 fields: Block name*, Description*, Category (never empty), Block type (registry keys only), Icon (320 px popover, search, six-column grid of 28 px tiles; defaults from the Category; "Change"/"Use category icon"). Empty required fields on Continue show inline errors, focus the first, keep other values (lenient save stays lenient). Display & behavior: default width in columns, default height (Small, Medium 360 px, Large), Show block header, Show subtitle, Allow resize, Allow minimize, Allow refresh, Show footer action, min and max size, period behaviour (Follow dashboard default, Own selector, Not date-filtered); values outside the registry's size limits are rejected (client and server 422) with the allowed range; "Own selector" is only stored; Show footer action adds the Footer action slot as optional-empty. Server save accepts `config` and `step` (stored in `draft_ui_state`), validating against the registry; `config` carries `schema_version`. Live preview pane ("Live preview", "Changes appear here automatically.") re-renders on every change with the kpi-card Loading skeleton; Desktop/Tablet/Mobile segmented toggles change canvas width; size pill reads e.g. "3 columns × Small"; `version-safe` under the preview naming the version. Without `blocks.publish` (from `useShell().can`): Publish block via `GatedAction` with `perm-publish`; Save draft works. Preview sticky on the right at about 45% of content width in steps 1-3; form section cards (1 px border, radius lg, padding 16, 28 px accent-soft icon tile, title, caption). New expand-only migration for `block_categories` (workspace_id, name, archived default false, RLS ENABLE+FORCE, workspace_isolation) owned by Blocks; add `category_id` to `blocks`; "General" is created lazily as `app` in the workspace transaction (idempotent) the first time the wizard or categories are read, and for existing data by the same path. Update dependencies.php, Cluster seeders, leak/privilege tests, error codes, copy in `blockWizardLabels`.

**Decided:** Keep the shipped shell breakpoint (full sidebar at 1280, icon rail below); the story's 1440 figure is not applied app-wide. Limits come from the registry (kpi-card: width 2-6, height small-medium), so an example such as "8 columns × Medium" is rejected for kpi-card.

**Never:** No Step 2-5 content (placeholders for later steps only), autosave/debounce/session recovery (3.5), lock endpoints (3.6), category management UI or ordering (Epic 6), Block Period Selector rendering, footer action target.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Open | no draft id | wizard on step 1, General selected | N/A |
| Reopen | `?draft=id&step=2` | Draft loaded at stored step | unknown/discarded id 404 page state |
| Missing required | empty name/description, Continue | inline errors, focus first, values kept | N/A |
| Out of limits | width 8, height large | rejected with allowed range | 422 field errors |
| Toggle | Tablet selected | canvas width changes, pill unchanged | N/A |
| No publish perm | no `blocks.publish` | Publish aria-disabled + `perm-publish`, Save works | N/A |
| Footer action on | toggle on | footer_action slot added optional-empty | N/A |
| First open | no categories | General exists, selected | N/A |
| Secret in config | secret-valued field | 422 | nothing stored |

</frozen-after-approval>

## Code Map

- `app/Modules/Blocks/{Contracts/Drafts.php,Contracts/Draft.php,Application/ManageDrafts.php}` -- extend save with `config`/`step`, add show; `checkType` shows registry access; JSON via `App\Platform\Json` (json_decode is banned)
- `app/Http/{Controllers/Admin/DraftController.php,Requests/Admin/DraftRequest.php,Resources/DraftResource.php}`, `routes/{api,web}.php`, `app/Http/Navigation/ShellNavigation.php` -- API/page wiring; AdminRoutesTest requires mapping
- `database/migrations/2026_10_17_100000_create_blocks_and_drafts.php` -- RLS migration pattern; `blocks.category` is a varchar today
- `tests/Architecture/dependencies.php`, `tests/Database/Support/Cluster.php` (`seedTenantRow`) -- register block_categories
- `resources/js/components/{PageHeader,FormField,FormErrorSummary,SegmentedControl,GatedAction,ConfirmDialog,UnsavedChangesDialog}.vue`, `ui/{input,textarea,switch}`, `composables/useBlurValidation.ts`, `composables/useShell` -- reuse; popover (reka-ui), icon grid, stepper, section card, preview pane are new
- `block-types/kpi-card/v1/{Renderer.vue,payload.ts}`, `resources/js/types/block-types.ts` (`kpiCardV1`) -- loading-state preview and limits
- `resources/js/pages/admin/DraftBlocks.vue`, `lib/draftBlocks.ts` (`draftWizardUrl`), `tests/js/draft-blocks.test.ts` -- list links to wizard; test style
- `resources/js/locales/{en.ts,labels.ts}` -- `perm-publish`, `version-safe` exist; add `blockWizardLabels`

## Tasks & Acceptance

**Execution:**
- [ ] migration + `app/Modules/Blocks/**` -- block_categories, category_id, lazy General, config/step save with registry validation, show draft
- [ ] routes, controller, request, resource, ShellNavigation -- show endpoint, config/step fields, categories read
- [ ] `resources/js/pages/admin/BlockWizard.vue` + wizard components/lib/labels -- shell, stepper, step 1 form, icon popover, preview pane
- [ ] registries/tests: Cluster, dependencies, error codes, Database tests (limits 422, config schema, General idempotent, RLS), Vitest (stepper a11y, validation, toggles, gated publish, preview)

**Acceptance Criteria:**
- Given the I/O matrix rows, when exercised, then each outcome holds.
- Given the full suite, when `bin/tools composer ci:check` runs, then it passes.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route / evidence |
|---|---|---|
| Draft whose category is archived cannot be saved (422) | medium | patch: allow an unchanged category; client keeps current in options |
| Wizard 422 with unmapped field keys or secret refusal fails silently | medium | patch: fallback error toast + focus |
| Config save replaces the whole document; later steps' keys would be wiped | medium | patch: step-1 save merges its own keys, keeps unknown keys |
| 409/423 bodies carry default config, not the stored one | medium | patch: load config for `current` |
| Draft on existing Block skips General ensure | low | patch |
| `category_id` lacks uuid rule; config unbounded | low | patch |
| Tests: category-name search; "no DELETE" test asserts nothing; `saveStep` insert fallback | medium | patch |
| Wizard keeps stale revision after 409/423 | low | defer: recovery is Story 3.5/3.6 |
| Client hardcodes block types/heights | low | defer: one registered type |
| GET endpoints run `ensure` (idempotent write); null cannot clear; step can move back; type change not revalidated; unknown registry height; archived names in list; stacked docblocks; seven hand-bumped rollback steps | low | reject: no demonstrated user harm at this scope |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
