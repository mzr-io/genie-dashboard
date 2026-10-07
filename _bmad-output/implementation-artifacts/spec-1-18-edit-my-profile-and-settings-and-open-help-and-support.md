---
title: 'Edit my profile and settings, and open Help & support'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '00dff801458673cd4c0a75ce46c6d257cb5a6829'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-17-switch-the-active-workspace.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-14-reset-a-forgotten-password.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The Profile and Security pages are starter-kit forms with no avatar, locale, time zone or shortcuts setting, the password change does not require the current password through the Dashflow rules, invalidate other sessions or audit, and Help & support is a placeholder (Story 1.18; FR-4, NFR-11, NFR-4, AR-38, UX-DR-23, 169, 263, 269, 286, 262). The Story 1.18 section of `epics.md` holds the acceptance criteria.

**Approach:** Add the per-user settings to the global `users` table, rebuild Profile & settings on the Story 1.8 and 1.9 components with catalogue copy, harden the password change like the reset flow, apply locale and time zone to client formatting, let the Keyboard shortcuts switch gate single-key shortcuts, and read Help & support links from a Workspace settings row.

## Boundaries & Constraints

**Always:** Columns `locale`, `timezone`, `avatar_path` and `keyboard_shortcuts` (boolean, default true) are added to `users` (a global table; no `workspace_id`). The Profile page shows name, avatar upload, locale, time zone, the Change password section and the Keyboard shortcuts switch, and no theme or Appearance control. Locale offers only the locales the catalogue supports (`en` today, read from one list so a later locale is additive); time zone offers PHP's identifiers. A valid save announces `saved` politely, keeps focus on Save, and applies the locale and time zone to number, currency and date formatting through one client formatting helper (`Intl` with the user's locale and time zone, lossless numbers per NFR); a failed save shows `save-failed` (form wording), keeps values and moves focus to the message. Avatar upload accepts PNG, JPEG and WebP only (no SVG), validated by content and extension, stored under a random name on a private disk, and served through an authenticated route with `Content-Type` fixed from the stored type, `X-Content-Type-Options: nosniff` and no caching of other users' images; the maximum size is the tunable `profile.avatar_max_bytes` (`pending_input`), falling back to PHP's `upload_max_filesize` while unset; a wrong type or oversize is refused with a field-level error and the existing avatar stays; replacing deletes the old file. The password change requires the current password (a wrong one is a field error and nothing changes), applies `Password::defaults()`, deletes every other session of the user (the current one is regenerated and destroyed old row), keeps the person signed in, and records `identity.password.changed` through `Audit::recordSecurityEvent` (log line when the person has no Workspace). The Keyboard shortcuts switch off disables single-key shortcuts (the shortcut registry in `resources/js/lib/` gates them) while modifier shortcuts and widget keys keep working. Help & support lists the links stored in the tenant table `workspace_settings` (`workspace_id` unique, `revision`, `help_links` json of `{label, url}` with http or https only, `contact_href` nullable), created here with RLS forced and read-only in this story; an empty list shows `list-empty`; "Contact your workspace administrator" links to `contact_href` when set and is plain text otherwise; links render as escaped text with `rel="noopener noreferrer"` and open in a new tab. All strings come from the catalogue or `labels.ts`; the pages use `FormField`, `FormErrorSummary`, `useBlurValidation`, the unsaved-work helper (`registerUnsavedForm`) and the generic list and form states of UX-DR-263.

**Never:** Editing help links or `contact_href` (Epic 8); additional locales; theme or Appearance settings; avatar cropping; sending email on password change (deferred in Story 1.14); invented size limits as defaults; storing avatars in the public web root.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Page | Profile & settings loads | Name, avatar, locale, time zone, Change password, shortcuts switch; no Appearance | N/A |
| Save | Valid name, locale or time zone | `saved` announced; focus on Save; formatting follows the new locale and time zone | Failure shows `save-failed`, values kept, focus on message |
| Password change | Correct current, valid new, confirmation | Changed; other sessions deleted; `identity.password.changed`; still signed in | N/A |
| Wrong current | Wrong current password | Field error; nothing changes | N/A |
| Avatar | Wrong type, SVG or oversize | Field-level error; existing avatar stays | Old file kept |
| Avatar served | Another user requests it | Authenticated route; fixed content type; `nosniff` | Missing file 404 |
| Shortcuts off | Switch Off, saved | Single-key shortcuts disabled; modifier and widget keys work | N/A |
| Help | Links configured | Escaped links, new tab, `rel` set; contact link from `contact_href` | Empty list shows `list-empty` |
| Cross-tenant | Another Workspace's settings row | Not readable (RLS) | N/A |

</frozen-after-approval>

## Code Map

- `app/Http/Controllers/Settings/ProfileController.php`, `SecurityController.php`, `app/Http/Requests/Settings/*`, `routes/settings.php`, `resources/js/pages/settings/Profile.vue`, `Security.vue`, `layouts/settings/Layout.vue` -- starter-kit settings pages and routes (Profile registers `registerUnsavedForm` since Story 1.17); `app/Models/User.php`
- `app/Modules/Identity/` -- add profile and password-change application services, avatar storage; `app/Modules/Access/` unchanged; `app/Platform/Audit/AuditAction.php` -- `identity.password.changed` exists
- `app/Http/Controllers/HelpController.php`, `resources/js/pages/auth/Help.vue`, `Placeholder.vue`, `lib/shell.ts` -- Help route and page (public for guests; settings read only for signed-in people)
- `database/migrations/` -- Stories 1.10 to 1.17; `tests/Architecture/dependencies.php` -- `workspace_settings` already listed for the Platform kernel; add its read through the kernel, not a module
- `resources/js/lib/formDrafts.ts`, `unsavedForms.ts`, `locales/en.ts`, `labels.ts`, `config/dashflow.php` -- tunables, strings, helpers; `tests/Feature/Settings/*`, `tests/Feature/Auth/*`, `tests/Database/`, `tests/js/` -- existing suites

## Tasks & Acceptance

**Execution:**
- [ ] migrations (`users` columns, `workspace_settings`), `config/dashflow.php`, `app/Modules/Identity`, controllers, requests, routes -- profile, avatar, password change, help read, tunable
- [x] `resources/js/pages/settings`, `pages/auth/Help.vue`, `lib` (formatting helper, shortcut registry), `labels.ts`, `AppSidebar` user data -- pages, formatting, shortcuts switch, avatar display
- [x] `tests/Feature`, `tests/Database`, `tests/js`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given a valid profile change, when saved, then `saved` is announced, focus stays on Save and formatting follows the new locale and time zone.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Implementation scope notes (frozen block untouched).** (a) The starter kit's separate Security page, its `security.edit` route and the delete-account feature (`profile.destroy`, `DeleteUser.vue`) were removed: the password form now lives in Profile & settings, and delete-account could not work because role `app` has no DELETE on `users`. The planning documents place user deletion with the operator or a later self-service story, so nothing replaces it here. (b) Email is not editable on Profile (the spec lists no email field); AR-38 leaves email self-service or operator-only, so a later identity story must add that path. Both are for the human to confirm.
- **Review iteration 1 (clarification).** The avatar route serves a picture only to its owner and to people who share an active Workspace with them.
- **KEEP:** the `workspace_settings` table with forced RLS, `AvatarStore` random names on a private disk, the single `format.ts` helper and the shortcut registry.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| When `upload_max_filesize` is `-1`, `0` or unparsable the limit becomes 0 and every avatar is refused as too large | high | patch | `AvatarLimit::bytes()` returns 0; the request compares `getSize() > 0`; the "falls back to PHP" promise breaks. |
| `GET /avatars/{user}` serves any user's picture to any signed-in person across Workspaces (sequential ids) | high | patch | `users` is global; no tenant or membership check on the route. |
| No pixel-dimension cap (decompression bomb) and unmapped upload errors (`UPLOAD_ERR_*`, `post_max_size` dropping all fields) | medium | patch | A tiny file with a huge canvas passes `getimagesize`; a partial upload is reported as "too large". |
| Password change does not revoke API tokens; `regenerate` or the audit throwing after commit gives a 500 and no event; a failing change is a bare 500 | medium | patch | `HasApiTokens` is on `User`; `ChangePassword` runs those steps after the transaction. |
| `WorkspaceSettings::row()` swallows every `Throwable` inside the request's transaction (aborting it) with no log, and reads the row twice | medium | patch | An outage shows an empty list; links and contact can come from different revisions. |
| Avatar store file handle leak; old-file delete failure and save failure paths untested; avatar response headers unasserted | medium | patch | `fclose` skipped on throw; removing the cleanup keeps tests green. |
| Timezone alias not in `listIdentifiers`; `event.key` undefined; custom `role=textbox` widgets; duplicate shortcut id; date-only string a day early; preferences persist after sign-out in the same SPA; dead `securitySettings` label and stale shell fixtures | medium | patch | Small client defects named by the edge-case layer. |
| Avatar cannot be removed; metadata (EXIF) is kept because files are not re-encoded | medium | defer | Not in the acceptance criteria; needs an image library decision. |
| Shortcut switch verified only against synthetic registrations | low | defer | No single-key feature exists yet; the story that adds the drawer must register through the registry. |
| `PHP-refused upload` message wording; locale never applied server side; 420 time zones as a prop; recaller cookie after token rotation; orphan on concurrent saves; password audit lands in the most recent Workspace; avatar errors as English strings | low | rejected | `en` is the only locale; the catalogue is fixed at 89 keys; polish with no failing scenario. |
| Delete-account and email editing removed; `/settings/security` gone | medium | rejected (human to confirm) | Recorded in the Spec Change Log; no replacement path exists in this story. |

## Design Notes

`workspace_settings` is created now only because Help & support must list Admin-configured links; the planning documents place the table in the Platform kernel with a `revision`, and Epic 8 adds editing. The avatar size limit stays a `pending_input` tunable with PHP's own limit as the fallback so no Dashflow number is invented. "Contact your workspace administrator" does not expose admin addresses: it links only to an address the Admin configures.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
