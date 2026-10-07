---
title: 'Navigate the two-area shell with sign out and profile menu'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: '789cbe977c70c483273f1354f611b3b50ad38e7c'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-13-sign-in-as-user-or-admin.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-9-build-dialogs-toasts-banners-popovers-and-live-regions.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Signed-in pages use the starter kit's sidebar and header, which have neither the two Dashflow areas, the role-aware navigation, the profile menu, a safe sign-out nor the responsive and landmark behaviour (Story 1.16; FR-4, FR-1, NFR-7, NFR-9, UX-DR-9, 10, 79..81, 83..86, 121, 159..161, 263, 266, 267, 270, 275, 286). The Story 1.16 section of `epics.md` holds the acceptance criteria.

**Approach:** One shell (sidebar, top bar, profile menu, landmarks, skip link) driven by the session area, with an Inertia-shared navigation model, placeholder pages for every navigation target, a server sign-out that rotates then destroys the session and audits `identity.signout.completed`, and the 64px icon rail and mobile sheet reflow.

## Boundaries & Constraints

**Always:** The shell follows UX-DR-79, 80, 81, 83, 85, 86 and 121 with Story 1.6 tokens, Story 1.8 and 1.9 components and the catalogue and `labels.ts` for all copy. The session area (`user` or `admin`, from Story 1.13) selects the navigation: User area section "WORKSPACE" with Overview, My dashboards, Templates, Profile & settings, Help & support; Admin area section "ADMINISTRATION" with Admin overview, Block management, Create block, Draft blocks, Published blocks, Block categories, Dashboard templates, Data sources, User configuration, System settings, Audit log. The server builds the model and shares it as an Inertia prop; Admin items map to a permission through one constant (Admin overview: none; Block management, Create block, Draft blocks, Published blocks, Block categories: `blocks.edit`; Dashboard templates: `templates.manage`; Data sources: `data_sources.manage`; User configuration: `users.manage`; System settings: `settings.manage`; Audit log: `audit.view`), read from `membership_permissions` through the Access module; an item the Admin lacks is rendered `aria-disabled` and focusable with the `perm-denied` reason visible or via its description, never hidden, and its page request is not gated here (Story 1.19 enforces). Every item routes to a placeholder page with title, the `page-subtitles` text where one exists (Overview, Admin overview), and the generic `list-empty` state; routes are named and registered under `auth`. The active item has the accent-soft fill and the 3px leading bar. The footer holds Help & support, Sign out and a user row (circle avatar, name, role, ⋯); Appearance is not rendered. The profile menu from the user row shows name and role for the active Workspace with Profile & settings and Sign out, Esc closes it and focus returns to the user row. Sign out is a POST: the session id is regenerated, then the session is invalidated, the person lands on sign-in, `identity.signout.completed` is recorded through `Audit::recordSecurityEvent` in the active Workspace (log line when none) and the form-draft store is cleared. Below 640px the sidebar is the overlay sheet; at tablet widths it is the 64px icon rail with tooltips on hover and focus; at 320 px there is no horizontal page scroll. The top bar is a 64px `banner` landmark with the settings gear (tooltip "Settings") linking to Profile & settings (User) or System settings (Admin), a breadcrumb with the back button where applicable, and the notifications bell and ⌘K search as disabled-with-reason placeholders. `navigation`, `main` (labelled with the page name) and the "Skip to content" link exist on every page. List-bearing placeholder pages show the UX-DR-263 states (5 skeleton rows, load failure with Retry, `list-empty`).

**Never:** The Workspace switcher (Story 1.17; leave the sidebar slot with the current Workspace name only); route or API permission enforcement and the 403 denial audit (Story 1.19); profile editing (Story 1.18); the notifications and search features (Epic 8); an Appearance control; a dark theme.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| User shell | Area `user` | "WORKSPACE" with five items, each to a placeholder page | N/A |
| Admin shell | Area `admin` | "ADMINISTRATION" with eleven items | N/A |
| Missing permission | Admin without `blocks.edit` | Block management items `aria-disabled` with `perm-denied`, still focusable | Not hidden |
| Active item | Current route | accent-soft fill and 3px bar | N/A |
| Sign out | Selected in either area | Session id rotated, destroyed; lands on sign-in; `identity.signout.completed`; drafts cleared | Failed POST resumes the shell with an error toast |
| Profile menu | Opened from the user row | Name and role; Esc closes and focus returns to the user row | N/A |
| Reflow | 1280, 768, 640 and 320 px | Full sidebar, 64px rail with tooltips, overlay sheet; no horizontal scroll at 320 | N/A |
| Landmarks | Every page | `banner`, `navigation`, `main` named by page, skip link first | N/A |
| Placeholders | Bell and ⌘K | Disabled with a reason | N/A |
| No Appearance | DOM and tab order | No theme control | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/layouts/app/AppSidebarLayout.vue`, `AppHeaderLayout.vue`, `AppLayout.vue`, `components/AppSidebar.vue`, `AppHeader.vue`, `AppSidebarHeader.vue`, `NavMain.vue`, `NavFooter.vue`, `NavUser.vue`, `UserMenuContent.vue`, `Breadcrumbs.vue`, `components/ui/sidebar/` -- starter-kit shell restyled in Stories 1.6 and 1.9 (skip link, toast region, session dialog already mount in the layouts)
- `app/Http/Middleware/HandleInertiaRequests.php`, `routes/web.php`, `routes/settings.php` -- shared props and existing routes (`overview`, `admin.overview`, `help`, `dashboard` alias); `resources/js/pages/` -- `Dashboard.vue`, `admin/Overview.vue`, `Help.vue`, `settings/*`
- `app/Modules/Identity/Http/` -- sign-in and session controllers; add the sign-out controller; `app/Modules/Access/` -- `MembershipLookup`, `WorkspaceMembership`, `MembershipPermission`, `Permission` enum; `app/Platform/Audit/AuditAction.php` -- `identity.signout.completed` (add if missing)
- `resources/js/lib/formDrafts.ts` -- `clearFormDrafts`; `locales/en.ts` keys `page-subtitles`, `list-empty`, `perm-denied`; `locales/labels.ts`
- `tests/js/layouts.test.ts`, `tests/Feature`, `tests/Database` -- existing suites

## Tasks & Acceptance

**Execution:**
- [x] `HandleInertiaRequests`, `app/Modules/Access`, `app/Modules/Identity/Http`, `routes/web.php`, `AuditAction` -- navigation model with permission mapping, placeholder routes, sign-out flow
- [x] `resources/js/layouts`, `components`, `pages`, `labels.ts` -- sidebar, icon rail, sheet, top bar, profile menu, placeholder pages, generic states
- [x] `tests/js`, `tests/Feature`, `tests/Database`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given a signed-in User or Admin, when a page renders, then the matching navigation, landmarks and skip link are present and Sign out ends the session safely.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (clarification, frozen block untouched).** The `help` route is the one navigation target that stays outside `auth`: the sign-in page links to it for signed-out visitors (Story 1.13), so it renders the shell for signed-in people and the auth layout for guests. Every other navigation target is registered under `auth`.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Sign-out clears drafts and the prefetch cache before the POST succeeds, so a failed sign-out loses unsaved drafts; non-401/419 errors also show Inertia's error modal; `signingOut` never resets | high | patch | `useSignOut` clears first; `onHttpException` for other statuses does not return `false`; no `onFinish`. |
| An admin-area session whose role in the active Workspace is `user` (or whose membership is missing or inactive) still gets the Admin navigation | high | patch | `ShellNavigation` trusts the session area; a later demotion or deactivation must show the user menu or no items; Story 1.19 gating does not exist yet. |
| The overlay sheet stays open after navigating or signing out | medium | patch | No `setOpenMobile(false)` on link activation with a persistent layout. |
| `main` is labelled "Page content" and Help is never active in the Admin area on `/help` and `/settings/*` | medium | patch | `useShell` resolves the current item against the area's items only. |
| `help` is a route closure, so `route:cache` fails | medium | patch | `Route::get('help', fn ...)` in `routes/web.php`. |
| Tests missing: the `status = 'active'` filter of `EloquentMembershipPermissions`, `useSignOut` 401, 419 and network branches, a throwing `MembershipLookup` | medium | patch | Verification-gap layer; removing the filter or the try/catch keeps tests green. |
| Disabled items in the rail look enabled and the reason is announced twice; `<nav>` wraps the footer; profile and security titles hard-coded outside `labels.ts`; stale README sentence; audit failure logged without Workspace id | low | patch | Cosmetic and accessibility polish, cheap. |
| 232px and 64px widths are not set | false | rejected | `SIDEBAR_WIDTH` and `SIDEBAR_WIDTH_ICON` already read `--df-sidebar-width` (232px) and `--df-icon-rail-width` (64px) from `tokens.css`. |
| Ctrl+B toggle and `sidebar_state` cookie retired; `ListStates` loading and error unreachable from placeholders; two lookups per full visit; hardcoded client paths; `SignOut` session operations throwing | low | rejected | Breakpoint-driven by design; placeholders have no data source; the shell prop is lazy; polish. |

## Design Notes

The permission mapping is one constant because the planning documents name the permission catalogue and the Admin items but not which gates which; Story 1.19 may refine it. Disabled items stay focusable so a keyboard user can read why.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
