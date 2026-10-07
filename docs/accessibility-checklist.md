# Accessibility checklist (manual)

Automated coverage today is `tests/js/a11y.test.ts`: axe-core in happy-dom over the four pages below,
limited to the WCAG 2.1 A and AA rules that need no layout (names, roles, labels, ARIA validity, landmarks,
heading order), plus static 320 px reflow rules. Colour contrast is covered by the token contrast tests
(`tests/js/contrast.test.ts`). **Real-browser axe runs (contrast, layout, reflow) are deferred**: no browser
runs in CI yet, so the steps below are done by hand in a real browser before a release and whenever one of
these pages changes. Record the date, browser and result in the pull request.

Pages: **Sign in** (`/login`), **Reset password** (`/reset-password/{token}`), **Profile & settings**
(`/settings/profile`), **User configuration** (`/admin/users`, Admin area).

## Every page

1. Keyboard only: Tab from the address bar. The first stop is the skip link; it moves focus to the main
   content. Shift+Tab reverses the same order.
2. Focus order follows the reading order, no stop is skipped or trapped, and every interactive element
   shows a visible focus ring.
3. Enter and Space activate buttons, links and radios; Escape closes dialogs and popovers and returns focus
   to the control that opened them.
4. Zoom to 200% and then 400% (a 320 CSS px wide viewport): no content is cut off or overlaps, and no
   horizontal scrolling is needed except inside a data table.
5. Resize the window to 320 CSS px wide: the page reflows to one column with no horizontal page scroll.
6. Text-only zoom to 200% (browser setting): labels and messages are not clipped.
7. A screen reader announces the page title, the main landmark, headings in order, and each control's name
   and state.

## Sign in

- Tab order: skip link, role cards (arrow keys move between User and Admin), email, password, show/hide
  password, Remember me, Forgot password, the help link, the Sign in button.
- A failed submit moves focus to the error summary; its links move focus to the field.
- At 320 px the hero is hidden and the form is centred under the logo; no horizontal scroll.

## Reset password

- Tab order: new password, show/hide, confirm password, show/hide, submit, back to sign in.
- A failed submit focuses the error summary. The expired-link state shows one heading and a link to request
  a new one; focus lands on it.

## Profile & settings

- Tab order follows the sections top to bottom: profile fields, avatar, preferences, password.
- Unsaved-change warnings are reachable by keyboard; saving announces `saved` and does not move focus away.
- At 320 px each section is a single column.

## User configuration

- Tab order: toolbar (search, filters), table header sort buttons, row actions, pagination.
- Each row's actions menu opens with Enter, moves with arrow keys and closes with Escape; a confirm dialog
  traps focus and returns it to the row.
- After a save the row is highlighted and focused, and `saved` is announced; a rollback toast is not
  auto-dismissed.
- At 320 px the table scrolls inside its own region and the page itself does not scroll horizontally.
