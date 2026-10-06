---
name: Dashflow
description: Visual system for Dashflow, a domain-agnostic dashboard platform. Clean light enterprise shell, one yellow brand accent, dark sign-in hero. Light theme only in the MVP; tokens are named semantically so a dark theme can be added later without redesign.
status: final
created: 2026-10-05
updated: 2026-10-05
sources:
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md
  - ../../prds/prd-rmg-dashboard-platform-2026-10-02/prd.md
  - ../../prds/prd-rmg-dashboard-platform-2026-10-02/addendum.md
colors:
  # ---- BRAND TOKENS (overridable per Workspace in the future; V2) ----
  accent: '#FACC15'            # fills, primary buttons, active step, highlights, chart series-1 fills. Never text.
  accent-strong: '#EAB308'     # hover / pressed fill of accent surfaces
  accent-border: '#FDE047'     # "+ Add" border, hotspot hover (pointer only). Never text
  accent-soft: '#FEF9C3'       # selected nav item, active chip, role cards, sample badge fill
  accent-wash: '#FFFBEB'       # callouts, suggestion rows, free-space hint
  accent-ink: '#A16207'        # links and accent text on light surfaces. 4.92:1 on #FFFFFF, 4.58:1 on accent-soft, 4.63:1 on surface-canvas
  accent-ink-strong: '#854D0E' # text on accent-soft badges and the Value role chip; selected-state bars and borders. 6.85:1 on #FFFFFF, 4.47:1 next to accent (non-text)
  accent-ink-inverse: '#FDE047' # accent text on dark surfaces only (toast actions, sign-in tagline). 13.46:1 on surface-inverse, 13.01:1 on hero-chip
  on-accent: '#111827'         # text/icons on accent fills. 11.58:1 on accent
  logo-tile: '#FACC15'         # Dashflow logo square (default identity)
  logo-mark: '#111827'         # the four tiles inside the logo square
  # ---- FIXED SYSTEM TOKENS (never overridden by a Workspace) ----
  surface-canvas: '#F7F8FA'    # app background
  surface-card: '#FFFFFF'      # cards, Blocks, panes, drawer
  surface-sunken: '#F9FAFB'    # inset wells, table headers, workspace switcher, row hover
  surface-muted: '#F3F4F6'     # tags, type chips, dividers between rows
  border-default: '#E5E7EB'    # 1px card, drawer and Block borders
  border-strong: '#D1D5DB'     # decorative dashed outlines, secondary-button borders (the label identifies the control). 1.47:1 - never a form-control boundary
  border-control: '#80868F'    # every form-control boundary: input, select, textarea, checkbox, radio, switch track, segmented control. 3.67:1 on white, 3.45:1 on canvas, 3.51:1 on sunken
  text-primary: '#111827'      # 17.74:1 on white
  text-secondary: '#374151'    # body text on grey, nav items, pills on surface-muted. 10.31:1 on white, 9.37:1 on surface-muted
  text-muted: '#6B7280'        # secondary text, axis labels, placeholders. 4.83:1 on white, 4.55:1 on canvas
  text-subtle: '#9CA3AF'       # decorative marks only. 2.54:1 - never placeholders or informative text
  text-inverse: '#F9FAFB'      # text on toast / tooltip / annotation surfaces
  surface-inverse: '#111827'   # toast, tooltip
  scrim: '#111827'             # behind the mobile overlay sheet; opacity set by overlay-sheet.scrim
  shadow-color: '#111827'      # base colour of every floating shadow; each elevation sets its own opacity
  focus-ring: '#2563EB'        # 3px ring, 2px offset. 5.17:1 on white
  on-status: '#FFFFFF'         # glyphs on solid success / error / info fills
  success: '#16A34A'           # icons, progress bars, completed-step fill. Not for small text (3.30:1)
  success-text: '#15803D'      # positive deltas and success text. 5.02:1 on white [DECISION A7]
  success-soft: '#DCFCE7'      # success chip fill, High-confidence badge
  success-wash: '#F0FDF4'      # "Added" state fill, just-added row
  success-border: '#BBF7D0'
  error: '#DC2626'             # errors, negative deltas, Required-missing. 4.83:1 on white [DECISION A7]
  error-text: '#B91C1C'        # error text on error-soft. 5.91:1 [PROPOSED]
  error-soft: '#FEF2F2'
  error-border: '#FCA5A5'
  warning: '#C2410C'           # Low-confidence, Stale text. 5.18:1 on white, 4.52:1 on warning-soft
  warning-bar: '#F97316'       # confidence meter fill, non-text only
  warning-soft: '#FFEDD5'
  warning-border: '#FED7AA'
  info: '#1D4ED8'              # Overridden status, number type chip. 6.70:1 on white
  info-strong: '#1E40AF'       # info text on info-soft. 7.15:1
  info-soft: '#DBEAFE'
  # ---- SIGN-IN HERO (fixed; dark surfaces exist only here in the MVP) ----
  hero-surface: '#0A0E11'
  hero-surface-raised: '#11161A'
  hero-card: '#181920'
  hero-border: '#1D2229'
  hero-text: '#FFFFFF'         # 19.38:1 on hero-surface
  hero-text-muted: '#9099A6'   # 6.73:1 on hero-surface
  hero-chip: '#1C1C13'         # tagline pill fill; text uses accent-ink-inverse (13.01:1)
  # ---- DATA-MAPPING ROLE CHIPS (text / fill / border; all text >= 4.5:1 on its fill) ----
  role-label-text: '#374151'
  role-label-fill: '#F3F4F6'
  role-label-border: '#E5E7EB'
  role-value-text: '#854D0E'
  role-value-fill: '#FEF08A'
  role-value-border: '#FDE047'
  role-dimension-text: '#0F766E'
  role-dimension-fill: '#CCFBF1'
  role-dimension-border: '#99F6E4'
  role-measure-text: '#1D4ED8'
  role-measure-fill: '#DBEAFE'
  role-measure-border: '#BFDBFE'
  role-time-text: '#6D28D9'
  role-time-fill: '#EDE9FE'
  role-time-border: '#DDD6FE'
  role-filter-text: '#C2410C'
  role-filter-fill: '#FFEDD5'
  role-filter-border: '#FED7AA'
  # ---- CHART PALETTE (max 6 series, in this order; V5 as modified by R1, R2) ----
  chart-1: '#FACC15'           # series-1 FILLS only: bars, slices, progress fills (brand-following = accent) [DECISION R1]
  chart-1-stroke: '#A16207'    # series-1 lines, points and fill edges (brand-following = accent-ink). 4.92:1 on white [DECISION R1]
  chart-1-fill: '#FEF9C3'      # area fill under series 1
  chart-2: '#4C6EB1'           # slate blue. 5.03:1 on white [DECISION R2]
  chart-3: '#0F766E'           # teal. 5.47:1 on white [DECISION R2]
  chart-4: '#DB2777'           # pink. 4.60:1 on white [DECISION R2]
  chart-5: '#EA580C'           # orange. 3.56:1 on white [DECISION R2]
  chart-6: '#6B7280'           # grey. 4.83:1 on white [DECISION R2]
  chart-grid: '#F3F4F6'
typography:
  # Inter with system fallback: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif.
  # Every figure (KPI values, table numbers, deltas, axis ticks, times) uses tabular numerals (font-variant-numeric: tabular-nums).
  display-hero:
    fontFamily: Inter
    fontSize: 56px
    fontWeight: '500'
    lineHeight: '1.08'
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: '500'
    lineHeight: '1.25'
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '700'
    lineHeight: '1.3'
  title-md:
    fontFamily: Inter
    fontSize: 15px
    fontWeight: '600'
    lineHeight: '1.35'
  title-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '600'
    lineHeight: '1.35'
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: '1.5'
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: '1.45'
  caption:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '400'
    lineHeight: '1.4'
  label-caps:
    fontFamily: Inter
    fontSize: 11px
    fontWeight: '600'
    lineHeight: '1.4'
    letterSpacing: 0.08em
  kpi-headline:
    fontFamily: Inter
    fontSize: 26px
    fontWeight: '700'
    lineHeight: '1.15'
    letterSpacing: -0.01em
  kpi-card:
    fontFamily: Inter
    fontSize: 20px
    fontWeight: '700'
    lineHeight: '1.2'
    letterSpacing: -0.01em
  mono:
    fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace'
    fontSize: 12px
    fontWeight: '400'
    lineHeight: '1.45'
rounded:
  xs: 4px      # checkbox, the top corners of chart bars
  sm: 6px      # small buttons, tags, type chips, role chips, kbd
  md: 8px      # inputs, selects, buttons, icon tiles, nav items, thumbnails
  lg: 10px     # cards, Blocks, drawer rows, toasts, workspace switcher
  xl: 12px     # dialogs, desktop drawer when floating
  sheet: 16px  # mobile overlay sheet (leading corners)
  full: 9999px # chips, pills, badges, stepper numerals, avatars
spacing:
  '1': 4px
  '2': 8px
  '3': 12px
  '4': 16px
  '5': 20px
  '6': 24px
  '7': 28px
  '8': 32px
  '10': 40px
  page-gutter: 28px          # main content side padding, desktop
  page-gutter-mobile: 16px
  grid-gap: 14px             # gap between Blocks (measured in mockup 02)
  grid-columns: '12'
  grid-row-unit: 60px        # [PROPOSED] height presets are multiples of this
  block-height-small: 120px  # [PROPOSED] KPI-card height in mockup 02
  block-height-medium: 360px # PRD / mockup
  block-height-large: 540px  # [PROPOSED]
  sidebar-width: 232px
  icon-rail-width: 64px
  topbar-height: 64px
  drawer-width: 392px
  drawer-dim-strip-mobile: 28px
  preview-pane-width: 384px  # Map Data sticky live preview
  popover-width-palette: 560px        # command palette (⌘K)
  popover-width-notifications: 360px  # notifications panel
  popover-width-picker: 320px         # field picker / field tree, role menu, icon picker
  stepper-numeral: 22px
  stepper-connector: 56px
  target-min: 24px           # minimum pointer target
  target-chrome: 28px        # Block Chrome icons, chips, row actions, toast actions and close
  target-touch: 44px         # every target on touch layouts
  reflow-stack-below: 640px  # viewport width below which wizard rows stack (WCAG 1.4.10)
  slot-row-wrap-below: 760px # left-column width below which slot rows wrap to two lines [DECISION R7]
components:
  button-primary:
    background: '{colors.accent}'
    foreground: '{colors.on-accent}'
    hoverBackground: '{colors.accent-strong}'
    radius: '{rounded.md}'
    minHeight: 34px
    paddingX: 14px
    typography: '{typography.title-sm}'
  button-secondary:
    background: '{colors.surface-card}'
    foreground: '{colors.text-primary}'
    border: '1px solid {colors.border-strong}'
    radius: '{rounded.md}'
    minHeight: 34px
  button-ghost:
    background: transparent
    foreground: '{colors.text-secondary}'
    hoverBackground: '{colors.surface-sunken}'
    radius: '{rounded.md}'
  button-link:
    foreground: '{colors.accent-ink}'
    typography: '{typography.caption}'
    fontWeight: '600'
  button-destructive-soft:
    background: '{colors.error-soft}'
    foreground: '{colors.error-text}'   # 5.91:1 on error-soft
    border: '1px solid {colors.error-border}'
    radius: '{rounded.sm}'
    minHeight: '{spacing.target-chrome}'
  button-destructive:
    background: '{colors.error}'
    foreground: '{colors.on-status}'
    radius: '{rounded.md}'
    minHeight: 34px
  button-disabled:
    opacity: '45% on the control only'
    reason: '{typography.caption} {colors.text-secondary} at full opacity, next to the control'
  input:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-control}'
    focusBorder: '{colors.accent}'
    focusHalo: '0 0 0 3px {colors.accent-soft}'
    radius: '{rounded.md}'
    minHeight: 36px
    placeholder: '{colors.text-muted}'   # 4.83:1 on white
    errorBorder: '{colors.error}'
    errorMessage: '{typography.caption} {colors.error-text}, leading error icon'
  textarea:
    extends: input
    minHeight: 96px
    resize: vertical
  json-textarea:
    extends: textarea
    typography: '{typography.mono}'
    minHeight: 200px
    gutter: 'line numbers in {typography.mono} {colors.text-muted} on {colors.surface-sunken}, 1px {colors.border-default} right edge'
    errorLine: '{colors.error-soft} line band with a 3px {colors.error} left rule in the gutter'
    resultValid: '{typography.caption} {colors.success-text}, leading ✓'
    resultInvalid: '{typography.caption} {colors.error-text}, leading error icon, "Go to line n" {components.button-link}'
  select:
    extends: input
    chevron: '{colors.text-muted}'
  method-prefix:
    background: '{colors.surface-sunken}'
    foreground: '{colors.success-text}'
    typography: '{typography.mono}'
  segmented-control:
    background: '{colors.surface-sunken}'
    border: '1px solid {colors.border-control}'
    activeBackground: '{colors.surface-card}'
    activeBorder: '1px solid {colors.border-control}'
    activeFontWeight: '600'
    radius: '{rounded.md}'
  chip-category:
    background: '{colors.surface-card}'
    foreground: '{colors.text-secondary}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.full}'
    minHeight: '{spacing.target-chrome}'
  chip-category-active:
    background: '{colors.accent-soft}'
    foreground: '{colors.accent-ink-strong}'
    border: '1px solid {colors.accent-ink-strong}'
    fontWeight: '700'
    leadingMark: '✓ in {colors.accent-ink-strong}'
  radio:
    size: 16px
    border: '1px solid {colors.border-control}'
    background: '{colors.surface-card}'
    checkedDot: '8px {colors.accent-ink-strong} dot'   # 6.85:1 on white
    checkedBorder: '1px solid {colors.accent-ink-strong}'
    label: '{typography.body-md} {colors.text-primary}'
  switch:
    trackSize: '32×18px, {rounded.full}'
    trackOff: '{colors.surface-card} with 1px {colors.border-control}'
    trackOn: '{colors.accent-ink-strong}'
    thumb: '14px {colors.surface-card} circle; off: 1px {colors.border-control}'
    stateWord: '{typography.caption} {colors.text-secondary}: "On" / "Off" beside the track'
  tooltip:
    background: '{colors.surface-inverse}'
    foreground: '{colors.text-inverse}'
    typography: '{typography.caption}'
    radius: '{rounded.sm}'
    paddingX: 8px
    maxWidth: 280px
  skeleton:
    fill: '{colors.surface-muted}'
    radius: '{rounded.sm}'
    shimmer: 'a {colors.surface-sunken} sweep; static under reduced motion; stops after 5 s'
  tag:
    background: '{colors.surface-muted}'
    foreground: '{colors.text-secondary}'
    radius: '{rounded.sm}'
  badge:
    radius: '{rounded.full}'
    typography: '{typography.caption}'
    fontWeight: '600'
    paddingX: 8px
  badge-draft:
    background: '{colors.accent-soft}'
    foreground: '{colors.accent-ink-strong}'
  badge-sample-data:
    background: '{colors.accent-soft}'
    foreground: '{colors.accent-ink-strong}'
    border: '1px solid {colors.accent}'
    icon: 'S on {colors.accent} circle'
    radius: '{rounded.full}'
  badge-new:
    background: '{colors.accent}'
    foreground: '{colors.on-accent}'
  badge-operational:
    background: '{colors.success-soft}'
    foreground: '{colors.success-text}'
  badge-partial-data:
    background: '{colors.warning-soft}'
    foreground: '{colors.warning}'
  stepper-step:
    numeralSize: '{spacing.stepper-numeral}'
    connector: '{spacing.stepper-connector} × 1px {colors.border-default}'
    numeralBackground: '{colors.surface-muted}'
    numeralForeground: '{colors.text-secondary}'   # 9.37:1 on surface-muted
    label: '{colors.text-muted}'
  stepper-step-active:
    background: '{colors.accent-soft}'
    numeralBackground: '{colors.accent}'
    numeralForeground: '{colors.on-accent}'
    label: '{colors.accent-ink}'
    radius: '{rounded.full}'
  stepper-step-done:
    numeralBackground: '{colors.success}'
    numeralForeground: '{colors.on-status}'
    label: '{colors.text-secondary}'
    connector: '{colors.success}'
  block-card:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.lg}'
    shadow: 'none'
    headerPadding: '{spacing.3} {spacing.4}'
  block-chrome-header:
    iconTile: '28px, {colors.accent-soft} fill, {rounded.md}'
    title: '{typography.title-sm}'
    subtitle: '{typography.caption} {colors.text-muted}'
    controls: '{colors.text-muted}; refresh, minimize, more (⋯); {spacing.target-chrome} hit area'
    divider: '1px solid {colors.border-default}'
  resize-handle:
    hitArea: '{spacing.target-min} × {spacing.target-min}'
    glyph: '10px L-shape, 2px {colors.accent-ink-strong}'   # 6.85:1 on white
  kpi-card:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.lg}'
    label: '{typography.body-sm} {colors.text-muted}'
    value: '{typography.kpi-card}'
    deltaPositive: '{colors.success-text}'
    deltaNegative: '{colors.error}'
    deltaWord: '{typography.caption} weight 600 in the delta colour: "· better" / "· worse", shown only when Direction is Lower is better'
    iconTile: '28px, {rounded.md}, soft fill of the KPI colour'
  kpi-headline-in-block:
    value: '{typography.kpi-headline}'
  slot-row:
    background: '{colors.surface-card}'
    divider: '1px solid {colors.surface-muted}'
    columns: '18px status · 140px slot name · 168px field · minmax(0,1fr) presentation · 126px confidence/status · 96px action'
    paddingY: 8px
  slot-row-wrapped:            # left column narrower than {spacing.slot-row-wrap-below} [DECISION R7]
    line1: '18px status · minmax(0,1fr) slot name · 126px confidence/status · 96px action'
    line2: 'indented under the slot name: field · presentation summary, wrapping as needed'
  slot-row-stacked:            # viewport narrower than {spacing.reflow-stack-below}
    lines: 'status + slot name / field / presentation summary / pill + actions, each full width'
  slot-pill-optional:
    background: '{colors.surface-muted}'
    foreground: '{colors.text-secondary}'   # 9.37:1
  slot-pill-error:
    background: '{colors.error-soft}'
    foreground: '{colors.error-text}'       # 5.91:1
    border: '1px solid {colors.error-border}'
  slot-row-required-missing:
    background: '{colors.error-soft}'
    leftRule: '3px solid {colors.error}'
    nameColor: '{colors.error-text}'
    caption: '{typography.caption} {colors.text-secondary}'   # 9.42:1 on error-soft; never text-muted (4.42:1)
  slot-row-type-mismatch:
    background: '{colors.error-soft}'
    leftRule: '3px solid {colors.error}'
    caption: '{typography.caption} {colors.text-secondary}'
  slot-row-expanded:
    background: '{colors.accent-wash}'
    leftRule: '3px solid {colors.accent}'
  confidence-high:
    background: '{colors.success-soft}'
    foreground: '{colors.success-text}'
    meter: '{colors.success}'
  confidence-medium:
    background: '{colors.accent-soft}'
    foreground: '{colors.accent-ink}'
    meter: '{colors.accent}'
  confidence-low:
    background: '{colors.warning-soft}'
    foreground: '{colors.warning}'
    meter: '{colors.warning-bar}'
  role-chip:
    radius: '{rounded.sm}'
    typography: '{typography.caption}'
    fontWeight: '700'
    paddingX: 8px
    minHeight: '{spacing.target-min}'
  role-chip-label:
    foreground: '{colors.role-label-text}'
    background: '{colors.role-label-fill}'
    border: '1px solid {colors.role-label-border}'
  role-chip-value:
    foreground: '{colors.role-value-text}'
    background: '{colors.role-value-fill}'
    border: '1px solid {colors.role-value-border}'
  role-chip-dimension:
    foreground: '{colors.role-dimension-text}'
    background: '{colors.role-dimension-fill}'
    border: '1px solid {colors.role-dimension-border}'
  role-chip-measure:
    foreground: '{colors.role-measure-text}'
    background: '{colors.role-measure-fill}'
    border: '1px solid {colors.role-measure-border}'
  role-chip-time:
    foreground: '{colors.role-time-text}'
    background: '{colors.role-time-fill}'
    border: '1px solid {colors.role-time-border}'
  role-chip-filter:
    foreground: '{colors.role-filter-text}'
    background: '{colors.role-filter-fill}'
    border: '1px solid {colors.role-filter-border}'
  role-chip-none:
    foreground: '{colors.text-muted}'
    border: '1px dashed {colors.border-control}'
  drawer:
    background: '{colors.surface-card}'
    width: '{spacing.drawer-width}'
    borderLeft: '1px solid {colors.border-default}'
  drawer-row:
    columns: '64px thumbnail · 1fr text · auto action'
    radius: '{rounded.lg}'
    hoverBackground: '{colors.surface-sunken}'
    openBorder: '1px solid {colors.accent}'
    openShadow: '0 4px 16px {colors.shadow-color} at 6% opacity'
    justAddedBackground: '{colors.success-wash}'
    lockedBackground: '{colors.surface-sunken}'
    addedStatus: '"✓ Added" in {typography.caption} weight 600 {colors.success-text} on {colors.success-wash}, 1px {colors.success-border}; status text, not a control'
    actionMinHeight: '{spacing.target-chrome}'
  drawer-thumbnail:
    width: 64px
    height: 46px
    border: '1px solid {colors.border-default}'
    radius: '{rounded.md}'
  drawer-thumbnail-mobile:
    width: 52px
    height: 38px
  toast:
    background: '{colors.surface-inverse}'
    foreground: '{colors.text-inverse}'
    actionColor: '{colors.accent-ink-inverse}'
    actionMinHeight: '{spacing.target-chrome}'
    close: '× button named "Dismiss notification", {spacing.target-chrome} square, {colors.text-inverse} glyph'
    radius: '{rounded.lg}'
    shadow: '0 10px 30px {colors.shadow-color} at 25% opacity'
  just-added-outline:
    outline: '3px solid {colors.accent}'
    offset: 2px
    halo: '0 0 0 8px {colors.accent} at 18% opacity'   # follows a Workspace accent override
  free-space-hint:
    background: '{colors.accent-wash}'
    border: '2px dashed {colors.accent-border}'
    foreground: '{colors.accent-ink}'
    radius: '{rounded.lg}'
  preview-hotspot:
    idle: '1px dashed {colors.border-default}'
    hover: '1.5px solid {colors.accent} on {colors.accent-wash}'   # pointer hover only
    focus: 'the standard focus-ring: 3px {colors.focus-ring}, 2px offset'
  listbox-option-active:       # the keyboard-active option in pickers, menus, trees, the palette and switchers
    background: '{colors.accent-soft}'
    indicator: '2px inset {colors.focus-ring} outline (4.81:1 on accent-soft)'
  listbox-option-selected:     # the chosen value, distinct from the active option
    mark: 'leading ✓ in {colors.accent-ink-strong}; in trees a 3px {colors.accent-ink-strong} left bar'
  sidebar:
    background: '{colors.surface-card}'
    width: '{spacing.sidebar-width}'
    borderRight: '1px solid {colors.border-default}'
    sectionLabel: '{typography.label-caps} {colors.text-muted}'
  sidebar-nav-item-active:
    background: '{colors.accent-soft}'
    foreground: '{colors.text-primary}'
    fontWeight: '600'
    leadingBar: '3px {colors.accent-ink-strong}, {rounded.full}'
    radius: '{rounded.md}'
  icon-rail:
    width: '{spacing.icon-rail-width}'
    background: '{colors.surface-card}'
  workspace-switcher:
    background: '{colors.surface-sunken}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.lg}'
    avatar: '30px {colors.accent} tile, {rounded.md}'
  signin-hero:
    background: '{colors.hero-surface}'
    headline: '{typography.display-hero} {colors.hero-text}'
    body: '{typography.body-md} {colors.hero-text-muted}'
    tagline: '{colors.accent-ink-inverse} on {colors.hero-chip}, {rounded.full}'
  signin-role-card:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.lg}'
    indicator: 'the radio component at the top right'
  signin-role-card-selected:
    background: '{colors.accent-soft}'
    border: '2px solid {colors.accent-ink-strong}'   # 6.38:1 against accent-soft, 6.85:1 against white
    indicator: 'checked radio ({colors.accent-ink-strong} dot)'
    radius: '{rounded.lg}'
  dialog:
    background: '{colors.surface-card}'
    radius: '{rounded.xl}'
    shadow: '0 12px 40px {colors.shadow-color} at 12% opacity'
  overlay-sheet:
    background: '{colors.surface-card}'
    radius: '{rounded.sheet} on the leading corners'
    shadow: '0 12px 40px {colors.shadow-color} at 12% opacity'
    scrim: '{colors.scrim} at 45% opacity'
    closeButton: '{spacing.target-touch} square, visible in the sheet header'
  focus-ring:
    outline: '3px solid {colors.focus-ring}'
    offset: 2px
  checkbox:
    size: 16px
    radius: '{rounded.xs}'
    border: '1px solid {colors.border-control}'        # 3.67:1 on white
    checkedBackground: '{colors.accent}'
    checkedBorder: '1px solid {colors.accent-ink-strong}' # 6.85:1 on white
    checkmark: '2px {colors.on-accent} check'           # 11.58:1 on accent
    hitArea: '{spacing.target-min}, including the label'
  icon-button-topbar:
    extends: button-ghost
    size: 36px
    icon: '18px {colors.text-muted}'
  header-picker-button:
    extends: button-secondary
    leadingIcon: '16px {colors.text-muted}'
    label: '{typography.title-sm} {colors.text-primary}'
    trailingChevron: '› {colors.text-muted}'
  back-button:
    extends: button-secondary
    size: 32px
    icon: '← 16px {colors.text-secondary}'
  page-header:
    title: '{typography.headline-md} {colors.text-primary}'
    subtitle: '{typography.caption} {colors.text-muted}'
  form-section-card:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.lg}'
    padding: '{spacing.4}'
    iconTile: '28px, {colors.accent-soft} fill, {colors.accent-ink} icon, {rounded.md}'
    title: '{typography.title-md}'
    subtitle: '{typography.caption} {colors.text-muted}'
    divider: '1px solid {colors.border-default}'
  preview-pane:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.lg}'
    title: '{typography.title-md}'
    subtitle: '{typography.caption} {colors.text-muted}'
    canvas: '{colors.surface-sunken} with 1px dots in {colors.border-default} every 12px'
    sizeLabel: '{typography.caption} {colors.text-muted} on {colors.surface-card}, 1px {colors.border-default}, {rounded.full}'
  callout-version-safe:
    background: '{colors.accent-wash}'
    border: '1px solid {colors.accent-border}'
    radius: '{rounded.lg}'
    icon: 'shield, 16px {colors.accent-ink}'
    title: '{typography.title-sm} {colors.accent-ink-strong}'
    body: '{typography.caption} {colors.text-secondary}'
  avatar-initials:
    radius: '{rounded.full}'
    sizeList: 32px
    sizeFeed: 28px
    typography: '{typography.caption}'
    fontWeight: '700'
    tints: '{colors.accent-soft}/{colors.accent-ink-strong} · {colors.role-dimension-fill}/{colors.role-dimension-text} · {colors.role-measure-fill}/{colors.role-measure-text} · {colors.role-time-fill}/{colors.role-time-text} · {colors.surface-muted}/{colors.text-secondary}'
  list-row:
    divider: '1px solid {colors.surface-muted}'
    paddingY: '{spacing.3}'
    title: '{typography.title-sm} {colors.text-primary}'
    subtitle: '{typography.caption} {colors.text-muted}'
    rightValue: '{typography.title-sm} {colors.text-primary}, tabular'
    rightMeta: '{typography.caption} {colors.text-muted}'
  progress-row:
    title: '{typography.title-sm} {colors.text-primary}'
    caption: '{typography.caption} {colors.text-muted}'
    percent: '{typography.title-sm} {colors.text-primary}, tabular'
    track: '6px {colors.surface-muted}, {rounded.full}'
    fillDefault: '{colors.chart-1}'   # supplementary; the % text is the value, the bar is aria-hidden
  activity-row:
    actor: '{typography.body-sm} 600 {colors.text-primary}'
    action: '{typography.body-sm} {colors.text-secondary}'
    time: '{typography.caption} {colors.text-muted}'
  calendar-week-strip:
    dayLetter: '{typography.caption} {colors.text-muted}'
    date: '{typography.title-sm} {colors.text-primary}, tabular'
    today: '28px {colors.accent} circle, {colors.on-accent} date, 4px {colors.accent} dot below'
  agenda-row:
    time: '{typography.caption} {colors.text-muted}, tabular'
    rule: '3px, {rounded.full}, colour from the category colour rule (chart palette)'
    title: '{typography.title-sm} {colors.text-primary}'
    subtitle: '{typography.caption} {colors.text-muted}'
  block-footer-link:
    foreground: '{colors.accent-ink}'
    typography: '{typography.caption}'
    fontWeight: '600'
    align: center
  chart-line:
    stroke: '2px, series colour ({colors.chart-1-stroke} for series 1; {colors.chart-2} to {colors.chart-6} for series 2 to 6)'
    curve: monotone
  chart-series-style:          # mandatory secondary encoding when a chart has 2 or more series [DECISION R2]
    dashes: 'series 1 solid · 2 dash · 3 dot · 4 dash-dot · 5 long-dash · 6 dotted'
    markers: 'series 1 ● · 2 ■ · 3 ▲ · 4 ◆ · 5 ✕ · 6 ○, drawn at every point when points are 24 or fewer, otherwise at the line end'
    directLabel: '{typography.caption} weight 600 in {colors.text-secondary}, at the line end, with the series marker'
    fillPatterns: 'bar and pie series 3 to 6: diagonal, dots, cross-hatch, vertical lines, in 1px {colors.surface-card} over the series fill'
  chart-area:
    fill: 'vertical gradient, {colors.chart-1-fill} at the line to transparent at the baseline'
  chart-gridline:
    stroke: '1px {colors.chart-grid}, horizontal only'
  chart-axis-label:
    typography: '{typography.caption}'
    foreground: '{colors.text-muted}'
  chart-point-highlight:
    size: 10px
    fill: '{colors.surface-card}'
    stroke: '2px series stroke colour ({colors.chart-1-stroke} for series 1)'
  chart-point-focus:           # keyboard focus on a data point
    marker: 'chart-point-highlight plus a 3px {colors.focus-ring} ring at 2px offset'
    guide: '1px {colors.border-control} vertical guide'
  chart-tooltip:
    extends: tooltip
    content: 'series marker · series name · value (tabular) · X value'
  chart-legend:
    item: 'series marker and dash sample in the series stroke colour, then the name in {typography.caption} {colors.text-secondary}'
    gap: '{spacing.4}'
  chart-bar:
    fill: 'series colour; series 1 uses {colors.chart-1} with a 1px {colors.chart-1-stroke} edge'
    radius: '{rounded.xs} on the top corners only (the end away from the baseline)'
    groupGap: '30% of the category band'
    barGap: 2px
    stackDivider: '1px {colors.surface-card} between stacked segments; stack order bottom-up in palette order'
    baseline: '1px {colors.border-control} at zero'
    valueLabel: '{typography.caption} {colors.text-secondary}, tabular, above the bar or inside the segment end when it fits'
  chart-pie:
    slice: 'series colour in palette order, largest first, clockwise from 12 o’clock; series 1 uses {colors.chart-1} with a 1px {colors.chart-1-stroke} edge'
    sliceDivider: '2px {colors.surface-card} between slices'
    donutThickness: '28% of the radius'
    centreValue: '{typography.kpi-headline} {colors.text-primary}, tabular'
    centreLabel: '{typography.caption} {colors.text-muted}'
    sliceLabel: '{typography.caption} {colors.text-secondary}: name and %, outside the ring with a 1px {colors.border-control} leader'
  table-header:
    background: '{colors.surface-sunken}'
    typography: '{typography.caption}'
    fontWeight: '600'
    foreground: '{colors.text-secondary}'
    divider: '1px solid {colors.border-default}'
  table-row:
    typography: '{typography.body-sm}'
    foreground: '{colors.text-primary}'
    divider: '1px solid {colors.surface-muted}'
    minHeight: 40px
    hoverBackground: '{colors.surface-sunken}'
  table-cell-numeric:
    align: right
    typography: '{typography.body-sm}, tabular numerals'
  table-sort-indicator:
    sorted: '▲ / ▼ 10px {colors.text-secondary} after the header label; the header label weight 700'
    unsorted: '↕ 10px {colors.text-muted}, shown on sortable columns only'
  table-pagination:
    range: '{typography.caption} {colors.text-muted}, tabular: "1–10 of 48"'
    controls: 'Previous / Next {components.button-secondary} at {spacing.target-chrome}, plus a page-size select'
  table-row-highlight:
    background: 'the band status soft fill ({colors.success-soft}, {colors.warning-soft}, {colors.error-soft} or {colors.info-soft})'
    mark: 'the band icon and label in the first cell, in the band text colour'
  text-status-face:
    text: '{typography.body-md} {colors.text-primary}, up to 3 lines'
    status: 'a status pill: band soft fill, band text colour, leading band icon, the status word in {typography.title-sm}'
  admin-metric-card:
    extends: kpi-card
    iconTile: '28px, {colors.accent-soft} fill, {colors.accent-ink} icon, {rounded.md}'
    label: '{typography.body-sm} {colors.text-muted}'
    value: '{typography.kpi-card}'
    deltaPositive: '{colors.success-text}'
    deltaNegative: '{colors.error}'
    deltaNeutral: '{colors.text-muted}'
    deltaWord: '{components.kpi-card.deltaWord}'
  activity-admin-row:
    iconTile: '28px, category soft fill, {rounded.md}'
    title: '{typography.title-sm} {colors.text-primary}'
    meta: '{typography.caption} {colors.text-muted}'
    time: '{typography.caption} {colors.text-muted}, tabular'
    divider: '1px solid {colors.surface-muted}'
  health-row:
    dot: '8px; {colors.success} / {colors.warning} / {colors.error}'
    name: '{typography.body-md} {colors.text-primary}'
    statusWord: '{typography.caption} {colors.text-muted}'
    uptime: '{typography.title-sm} {colors.text-primary}, tabular'
    divider: '1px solid {colors.surface-muted}'
  # ---- GENERIC ADMIN AND SETTINGS PARTS ----
  data-table:                  # every Admin list page and My dashboards
    toolbar: 'search input (leading icon, visible label or aria-label) · filter selects · count in {typography.caption} {colors.text-muted} · primary action on the right'
    container: '{colors.surface-card}, 1px {colors.border-default}, {rounded.lg}'
    header: '{components.table-header}'
    row: '{components.table-row}'
    rowSelected: '{colors.accent-soft} fill plus a 3px {colors.accent-ink-strong} leading bar'
    rowMenu: '⋯ {components.button-ghost} at {spacing.target-chrome}, named "More actions for {row name}"'
    statusCell: 'a badge or a dot plus its word; never a dot alone'
    emptyRow: 'one full-width row: {typography.body-sm} {colors.text-muted} message and the primary action'
    loadingRows: '5 skeleton rows at table-row height'
    pagination: '{components.table-pagination}'
  date-range-calendar:
    presets: 'a vertical list of presets on the left, each a {components.listbox-option-active} row when active'
    months: 'two months side by side (one below 640px); weekday letters in {typography.caption} {colors.text-muted}'
    day: '{spacing.target-chrome} square, {typography.body-sm} tabular, {rounded.full}'
    today: '1px {colors.border-control} ring'
    rangeEnds: '{colors.accent} fill with {colors.on-accent} date'
    rangeBetween: '{colors.accent-soft} band, dates in {colors.text-primary}'
    focusDay: 'the standard focus-ring'
    footer: 'the chosen range in {typography.caption} {colors.text-secondary}, then Cancel and Apply ({components.button-primary})'
  icon-picker:
    width: '{spacing.popover-width-picker}'
    search: 'input at the top'
    grid: '6 columns of {spacing.target-chrome} + 8px tiles, 20px icon in {colors.text-secondary}'
    tileActive: '{components.listbox-option-active}'
    tileSelected: '{colors.accent-soft} fill, 1px {colors.accent-ink-strong} border, ✓ badge'
    footer: '"Use category icon" {components.button-link}'
  expanded-view:
    container: 'a full-height panel over the dashboard with the {components.dialog} surface, 90% of the viewport up to 1200px wide; full screen below 768px'
    header: 'the Block icon tile, title in {typography.headline-md}, Data-as-of Time in {typography.caption} {colors.text-muted}, Close button'
    body: 'the Block at full size, then its rows in a {components.data-table} with {components.table-pagination}'
  edit-layout-toolbar:
    controls: 'Undo and Redo {components.button-secondary} icon buttons with text labels, then "Done" {components.button-primary}'
    hint: '{typography.caption} {colors.text-secondary}: "Drag to move · corner to resize · M to move with the keyboard"'
  live-updates-toggle:         # dashboard header [DECISION R4]
    control: '{components.button-secondary} with a pause / play glyph: "Pause live updates" / "Resume live updates"'
    pausedLabel: '{typography.caption} {colors.text-secondary}: "Paused · data as of 10:42"'
  skip-link:
    background: '{colors.surface-inverse}'
    foreground: '{colors.text-inverse}'
    typography: '{typography.title-sm}'
    position: 'hidden until focused; then top left of its region, above all sticky layers'
  banner-dismissible:
    family: 'the banner family (see Components → Banners)'
    dismiss: '× button named "Dismiss", {spacing.target-chrome} square'
  # ---- MAP DATA PARTS (behaviour: EXPERIENCE.md → Map Data) ----
  mapdata-status-bar:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-default} bottom'
    padding: '{spacing.3} {spacing.4}'
    summary: '{typography.title-sm} {colors.text-primary}, tabular counts'
    subtitle: '{typography.caption} {colors.text-muted}'
    actions: '"↻ Re-run auto-map" {components.button-ghost}; "Continue to Preview →" {components.button-primary}'
  status-meter:
    segment: 'one 6px-tall segment per Slot, equal widths, 2px gaps, {rounded.full} ends'
    colours: 'Confirmed or High {colors.success} · Medium {colors.accent} · Low {colors.warning-bar} · Overridden {colors.info} · Optional-empty {colors.surface-muted} · Required-missing or Type-mismatch {colors.error}'
    semantics: 'aria-hidden; the summary text is the value'
  scalar-card:
    background: '{colors.surface-card}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.md}'
    padding: '{spacing.3}'
    key: '{typography.mono} {colors.text-primary}'
    example: '{typography.mono} {colors.text-muted}'
    qualifier: '{typography.caption} {colors.text-secondary} after the role chip ("· main", "· baseline")'
    layout: 'a wrapping row of cards, 4 per row at most; 1 per row below {spacing.reflow-stack-below}'
  roles-table:
    roleHeader: 'a row of role chips above the sample columns, one per column'
    sample: '{components.table-header} and {components.table-row}; values in {typography.mono}; at most 5 rows'
    scrollRegion: 'horizontal scroll inside a focusable region with a 1px {colors.border-default} edge and an edge fade'
  roles-summary-line:
    background: '{colors.surface-sunken}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.md}'
    text: '{typography.body-sm} {colors.text-secondary}; fields in {typography.mono}, each followed by its role chip'
    action: '"Edit roles" {components.button-link}'
  suggestion-row:
    background: '{colors.accent-wash}'
    border: '1px solid {colors.accent-border}'
    radius: '{rounded.md}'
    glyph: '✦ in {colors.accent-ink-strong}'
    text: '{typography.body-sm} {colors.text-primary}'
    actions: 'accept {components.button-secondary}, dismiss {components.button-ghost}, both at {spacing.target-chrome}'
  record-path-list:
    row: 'the radio component · path in {typography.mono} {colors.text-primary} · "12 rows · 5 columns" in {typography.caption} {colors.text-muted} · hint in {typography.caption} {colors.text-secondary}'
    rowSelected: '{colors.accent-soft} fill, 1px {colors.accent-ink-strong} border, {rounded.md}'
    suggestedBadge: '"Suggested" badge: {colors.success-soft} with {colors.success-text}'
    paginationBadge: '"looks like pagination, not data": {components.tag}'
  aggregate-control:
    background: '{colors.surface-sunken}'
    border: '1px solid {colors.border-default}'
    radius: '{rounded.md}'
    sentence: '{typography.body-sm} {colors.text-primary} with inline compact selects ("Sum [sales.amount] by [sales.month]")'
    rowCounts: '{typography.title-sm} {colors.text-primary}, tabular: "36 rows → 12 rows"'
    note: '{typography.caption} {colors.text-secondary} ("ratio: sums numerator and denominator")'
  transform-row:
    columns: '120px stage label · minmax(0,1fr) sentence · auto row counts · {spacing.target-chrome} remove'
    stageLabel: '{typography.label-caps} {colors.text-muted}'
    sentence: '{typography.body-sm} {colors.text-primary} with inline compact inputs and selects ({components.input} at 28px min height)'
    rowCounts: '{typography.caption} {colors.text-muted}, tabular: "36 → 12 rows"'
    divider: '1px solid {colors.surface-muted}'
    error: '{colors.error-soft} fill, 3px {colors.error} left rule, message in {typography.caption} {colors.error-text}'
    stacked: 'below {spacing.reflow-stack-below}: stage label, then the sentence wrapping, then counts and remove'
  comparison-editor:
    container: 'inside slot-row-expanded'
    source: '{components.segmented-control}: "Mapped previous value" / "Prior-period request"'
    fields: 'selects and inputs in a two-column grid; one column below {spacing.reflow-stack-below}'
    example: '{typography.caption} {colors.text-secondary}: the live result, for example "↗ 14.2% from last year"'
  threshold-band-row:
    columns: 'minmax(0,1fr) condition · 132px colour select · 120px icon select · minmax(0,1fr) label · {spacing.target-chrome} remove'
    colourSelect: 'swatch plus the status name ("Success", "Warning", "Error", "Info", "Neutral"); never a bare swatch'
    order: 'a 20px order numeral in {typography.caption} {colors.text-muted} at the left ("1", "2"…)'
    stacked: 'below {spacing.reflow-stack-below}: condition, then colour · icon, then label'
  parameters-table:
    extends: data-table
    columns: 'Name ({typography.mono}) · Binding (select) · Value (input or select) · remove'
    resolved: '{typography.mono} {colors.text-muted} under the value: "from = 2026-01-01"'
    userContextChip: '"user context" {components.tag} with {colors.info-soft} fill and {colors.info-strong} text'
  fetch-error-card:
    background: '{colors.error-soft}'
    border: '1px solid {colors.error-border}'
    leftRule: '3px solid {colors.error}'
    radius: '{rounded.lg}'
    title: '{typography.title-sm} {colors.error-text}, leading error icon'
    body: '{typography.body-sm} {colors.text-secondary}'
    actions: 'Retry {components.button-secondary}; "Paste sample JSON instead" {components.button-link}'
    detailsToggle: '"▸ Technical details" disclosure button in {typography.caption} weight 600 {colors.text-secondary}'
    detailsPanel: '{colors.surface-card} inset, 1px {colors.border-default}, {rounded.md}; {typography.mono} {colors.text-primary} lines (status, request ID, host, reason); "Copy request ID" {components.button-ghost}'
  missing-slot-placeholder:
    background: '{colors.error-soft} with 45° hatching in 1px {colors.error-border} every 8px'
    border: '1px dashed {colors.error}'
    radius: '{rounded.md}'
    text: '{typography.caption} weight 600 {colors.error-text} on a {colors.surface-card} label plate'
    focus: 'the standard focus-ring'
  source-legend:
    heading: '{typography.label-caps} {colors.text-muted}: "WHERE EACH PART COMES FROM"'
    row: 'role chip · field in {typography.mono} {colors.text-secondary} · → · rendered result in {typography.body-sm} {colors.text-primary}, tabular'
    divider: '1px solid {colors.surface-muted}'
---

> **Tags (2026-10-05).**
> - `[ASSUMPTION]`, `[ASSUMPTION — accepted default]` and `[PROPOSED]`: **accepted working defaults** (decision log, 2026-10-05). They can be adjusted later without a product-level decision.
> - `[DECISION …]`: a product-owner decision recorded in the decision log (for example A7, V3).
> - `[UX DECISION — aligned in PRD v3.2]`: a UX decision that PRD v3.2 now matches.


## Brand & Style

Dashflow turns other people's APIs into dashboards that people trust. The look follows from that: a calm, light, enterprise shell where the data is the loudest thing on the page. It is modern and minimal, with clear hierarchy and generous spacing. One warm yellow accent says "this is the action" or "this is selected". Everything else is neutral grey on white.

The five product-owner mockups set the starting direction. They are a direction, not a locked design: [imports/01-sign-in.png](imports/01-sign-in.png), [imports/02-user-overview-add-blocks.png](imports/02-user-overview-add-blocks.png), [imports/03-admin-overview.png](imports/03-admin-overview.png), [imports/04-create-block-basic-data.png](imports/04-create-block-basic-data.png) and [imports/05-create-block-display-behavior.png](imports/05-create-block-display-behavior.png). The hex values come from the two accepted hybrid sketches, [mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html) and [mockups/addblocks-hybrid.html](mockups/addblocks-hybrid.html), which state their palettes in their `<style>` comments. **Where these images or sketches disagree with this file, this file wins.** In particular, the sketches still use colours that this file has since replaced for contrast: the active step label and block icon in #CA8A04, the `.up` delta in #16A34A, "Data as of" and subtle labels in #9CA3AF, the High and Confirmed pills at 3.00:1, and the pre-R2 chart palette. Build from the tokens here, not from the sketch CSS (review-accessibility.md C5, C9). The rejected alternatives (.working/) are listed in EXPERIENCE.md → Inspiration & Anti-patterns.

The Dashflow identity (yellow logo tile, yellow accent) is the MVP default. A Workspace may later override the **brand tokens** (logo and accent) and nothing else. Full white-labelling is not an MVP requirement.

Posture for possibly regulated buyers (healthcare, fintech): restraint, legibility and honesty about data. The UI never decorates numbers. It labels sample data, staleness and missing values in plain words.

## Colors

Every token name is **semantic** (what the colour is for, not what it looks like), so a dark theme can be added later without redesign (see *Dark theme readiness*). Hex values are in the frontmatter; this section states where each token is used, what it is never used for, and the contrast it achieves. The tokens fall into two groups.

**Brand tokens.** A Workspace may override these in the future (V2).

| Token | Used for | Rule and contrast |
|---|---|---|
| `{colors.accent}` | **Fills only**: primary buttons, the active stepper step, the selected nav item's companion fills, checkboxes, the "Just added" outline and halo, the logo tile, chart series-1 fills | **Never text, on any surface** (1.53:1 on white; V1). Neither is `{colors.accent-border}` |
| `{colors.accent-soft}` | Pale yellow for selected states: the active nav item, the active category chip, the selected sign-in role card, the Sample data and Draft badges | — |
| `{colors.accent-wash}` | Paler again: callouts, suggestion rows, the free-space hint | — |
| `{colors.accent-ink}` (dark amber) | **Text and links** on light surfaces: "View all approvals →", "Forgot password?", "+ Add", the active step label | **4.92:1 on white, 4.58:1 on accent-soft, 4.63:1 on the canvas, 4.75:1 on accent-wash**. All meet WCAG AA 4.5:1 |
| `{colors.accent-ink-strong}` | Small bold text on yellow fills | 6.85:1 |
| `{colors.accent-ink-inverse}` | Accent text **on dark surfaces only**: the toast actions ("Show me", "Undo") on surface-inverse and the sign-in tagline on hero-chip | 13.46:1 and 13.01:1. accent-ink would fail there (3.60:1 on surface-inverse, 3.48:1 on hero-chip), so the dark-surface case has its own brand token |
| `{colors.on-accent}` | Text on accent fills | 11.58:1 |
| `{colors.logo-tile}`, `{colors.logo-mark}` | The logo | — |

**Override guardrails** [PROPOSED derivation rule], covering accent, accent-ink and accent-ink-inverse:
- When a Workspace overrides the accent, on-accent switches automatically between `#111827` and `#FFFFFF`, whichever has the higher contrast.
- A Workspace-supplied accent-ink must meet 4.5:1 on white and on its own accent-soft; otherwise the system default amber is kept.
- A Workspace-supplied accent-ink-inverse must meet 4.5:1 on `{colors.surface-inverse}` and `{colors.hero-chip}`; otherwise the default is kept.
- `{colors.chart-1-stroke}` follows accent-ink and `{colors.chart-1}` follows accent.

**Fixed system tokens.** A Workspace never overrides these, so status and meaning stay consistent everywhere.

| Group | Tokens and rules |
|---|---|
| Surfaces | `{colors.surface-canvas}`, `{colors.surface-card}`, `{colors.surface-sunken}` (wells, hover rows), `{colors.surface-muted}` (tags, row dividers) |
| Text | `{colors.text-primary}`, `{colors.text-secondary}`, `{colors.text-muted}` (4.83:1 on white; also placeholders). `{colors.text-subtle}` (2.54:1) is for decorative marks only, **never for placeholders or information** such as chart axis labels |
| Borders | `{colors.border-default}` for card, Block and drawer edges. `{colors.border-control}` for **every form-control boundary**, including the segmented control's outer edge and the join of the endpoint method prefix. `{colors.border-strong}` only for decorative dashed outlines and secondary-button borders, where the label identifies the control |
| Shadows | `{colors.shadow-color}` at the opacity of each elevation; the mobile-sheet scrim is `{colors.scrim}` at 45%. No raw `rgba()` values |
| Status | `{colors.success}` fails as small text (3.30:1), so text uses `{colors.success-text}` (5.02:1). `{colors.error}` (4.83:1) marks errors, negative deltas and Required-missing; on error-soft, text uses `{colors.error-text}`. `{colors.warning}` marks Low confidence, Stale and partial data. `{colors.info}` / `{colors.info-strong}` mark the Overridden slot status and number type chips |
| Focus | `{colors.focus-ring}` is blue, deliberately not yellow, so focus is never confused with selection |
| Sign-in hero | `{colors.hero-surface}` and its family are the only dark surfaces in the MVP |

**Text on tinted fills.**
- Pills and captions on tinted fills use text-secondary, never text-muted: the Optional pill and the upcoming stepper numeral on surface-muted (9.37:1, where text-muted is 4.39:1), and captions inside error-soft rows (9.42:1, where text-muted is 4.42:1).
- Error words on error-soft use `{colors.error-text}` (5.91:1), never `{colors.error}` (4.41:1).
- **No text-muted on accent-soft** (4.50:1, no margin); use text-secondary or accent-ink-strong there.

**Replaced mockup colours** [DECISION A7]. The mockups' delta green (#00D987, 1.86:1) and delta red (#FF004A, 3.92:1) fail AA as text; they are replaced by `{colors.success-text}` and `{colors.error}`. The mockups' yellow (#FFDD1D) and the sketches' yellow differ slightly; the accent is #FACC15. Colour is never the only indicator.

**Data-mapping role chips** (`role-*`) give the six field roles stable colours: Label (grey), Value (yellow), Dimension (teal), Measure (blue), Time (violet) and Filter/Category (orange). Every role chip's text is at least 4.5:1 on its fill. Type chips reuse the same families: number = Measure blue, text = Label grey, date = Time violet, array = Filter orange.

**Chart palette (V5, modified by R1 and R2).** At most **6 series**, always in this order: yellow/amber (`{colors.chart-1}` fill, `{colors.chart-1-stroke}` stroke), `{colors.chart-2}` slate blue, `{colors.chart-3}` teal, `{colors.chart-4}` pink, `{colors.chart-5}` orange, `{colors.chart-6}` grey. [DECISION R2]
- **Series 1 is two tokens** [DECISION R1]. **Fills** (bars, slices, progress fills, the area wash) stay brand yellow chart-1. **Lines, points and fill edges** use dark-amber chart-1-stroke, and a yellow bar or slice carries a 1px chart-1-stroke edge so its shape is perceivable. Series 1 follows the brand (see *Override guardrails*).
- **Every series mark is at least 3:1 on white** (see the table below).
- **Secondary encoding is mandatory for 2 or more series** [DECISION R2]. Every such chart uses **direct labels** (at the line end, on the bar, or beside the slice) **or** the per-series **dash and marker** variants of `chart-series-style`. Bar and pie series 3 to 6 also take fill patterns. The legend shows each series' marker and dash, so a series is never identified by colour alone. The first four series stay distinguishable under protan and deutan simulation (ΔE ≥ 12.6); the weaker teal/grey pair only meets when 5 or 6 series are shown, where the dash and marker carry it.
- The tooltip names the series. A seventh series is not allowed; the Admin must group the extra values or use top-N.

**Non-text contrast (1.4.11)**, computed against the adjacent colour (target ≥ 3:1):

| Element | Colours | Ratio |
|---|---|---|
| Form-control boundary (`border-control`) | #80868F on white / canvas / sunken / surface-muted | 3.67 / 3.45 / 3.51 / 3.33 |
| Checked checkbox edge (`accent-ink-strong`) | #854D0E on white; against the accent fill | 6.85; 4.47 |
| Checked checkbox mark (`on-accent` on accent) | #111827 on #FACC15 | 11.58 |
| Radio checked dot, switch-on track, selected role-card border, sidebar active bar, selected-chip border (`accent-ink-strong`) | #854D0E on white / accent-soft | 6.85 / 6.38 |
| Active-option inset ring (`focus-ring`) | #2563EB on accent-soft / white | 4.81 / 5.17 |
| Focus ring (lowest surface) | #2563EB next to accent / on surface-inverse | 3.38 / 3.43 |
| Resize handle glyph (`accent-ink-strong`) | #854D0E on white | 6.85 |
| Chart series strokes | chart-1-stroke / 2 / 3 / 4 / 5 / 6 on white | 4.92 / 5.03 / 5.47 / 4.60 / 3.56 / 4.83 |
| Required-missing left rule (`error` on error-soft) | #DC2626 on #FEF2F2 | 4.41 |
| Success dot / step check fill | #16A34A on white | 3.30 |
| Supplementary only, not relied on: area fill (1.07), progress fill on its track (1.39), gridlines (1.10), "+ Add" border (1.32), just-added outline (1.47), hotspot idle dash (1.24), accent-soft selected fill (1.07). Each has a text, shape or ≥ 3:1 companion that carries the meaning. | | |

**Selected states never rely on a fill alone.** Accent-soft against white is 1.07:1, so every selected state adds a ≥ 3:1 cue: a 3px accent-ink-strong leading bar (sidebar and icon rail active item, selected table row), a 2px accent-ink-strong border plus a radio (sign-in role card), a 1px border-control edge and weight 600 (segmented control), a leading ✓ and accent-ink-strong border (category chip), or a ✓ / left bar (`listbox-option-selected`). The keyboard-**active** option in a picker, menu, tree, palette or switcher adds a 2px inset focus-ring outline (`listbox-option-active`), because there the highlight *is* the focus indicator.

**Dark theme readiness (V3).** The MVP ships the **light theme only**. [UX DECISION — aligned in PRD v3.2: NFR-10] A later dark theme adds a `-dark` counterpart for each system token (for example `surface-card-dark` or `text-primary-dark`) and for each brand token. Components reference only semantic tokens, never raw hex values, so adding dark is a token task, not a redesign. **Dark-theme contrast is unchecked while the dark theme is deferred** [DECISION 2026-10-05, gaps]: no `-dark` value is specified or contrast-checked now. The chart palette and status colours must be verified against the dark surfaces when the dark theme is built (NFR-7).

## Typography

**Inter**, with the system fallback stack in the frontmatter (V4). **Every figure uses tabular numerals**: KPI values, table numbers, deltas, axis ticks, times and counts. This keeps columns of numbers aligned and stops values from jittering on refresh.

| Role | Token | Used for |
|---|---|---|
| Hero | `{typography.display-hero}` | Sign-in hero headline only ("Turn complex work into clear decisions.") |
| Headline large | `{typography.headline-lg}` | Sign-in form title ("Sign in to your workspace") |
| Headline medium | `{typography.headline-md}` | Page titles: "Good morning, Alex", "System overview", "Create dashboard block" |
| Page subtitle | `{typography.caption}` in text-muted | The line under a page title: "Here's what's happening across your workspace today.", "Monitor dashboard adoption, publishing activity and platform health." (`page-header`) |
| Title medium | `{typography.title-md}` | Wizard section-card titles, "Live preview", Admin overview panel titles, drawer title, dialog titles |
| Title small | `{typography.title-sm}` | Block Chrome titles, button labels, slot names |
| Body | `{typography.body-md}` / `{typography.body-sm}` | Form text, descriptions, list rows. body-sm is the dense default inside Map Data and the drawer |
| Caption | `{typography.caption}` | Subtitles, helper text, meta lines, Data-as-of Time |
| Label caps | `{typography.label-caps}` | Sidebar section labels ("WORKSPACE", "ADMINISTRATION"), slot group headers ("HEADER", "KPI", "CHART"), "WELCOME BACK" eyebrow |
| KPI headline | `{typography.kpi-headline}` | The Headline value Slot inside a Block (for example $284,680 in "Revenue overview") |
| KPI card | `{typography.kpi-card}` | The value on a standalone KPI card Block and on Admin overview metric cards |
| Mono | `{typography.mono}` | JSON paths (`data.monthly[].revenue`), endpoints, sample values |

Rules:
- One headline per Block: the Slot whose emphasis is *headline* uses kpi-headline. Every other emphasis steps down (primary = title-sm, secondary = caption in text-muted, badge = badge component).
- No all-caps except label-caps. Never set body copy below 12px.

## Layout & Spacing

The spacing scale is 4-based (`{spacing.1}` to `{spacing.10}`). Related items sit 4 to 8px apart, items within a card 12 to 16px apart, and cards `{spacing.grid-gap}` apart.

**App shell (desktop).**
- **Sidebar** (`{spacing.sidebar-width}`): the logo, the workspace switcher, the area's navigation, and a footer with Help & support, Sign out, Appearance and the user.
- **Top bar** (`{spacing.topbar-height}`): search (⌘K), notifications, the settings gear (`icon-button-topbar`), and the area's primary action (for example "+ Add block"). The mockups' theme toggle is hidden in the MVP.
- **Breadcrumb strip**, then the page header, then content with `{spacing.page-gutter}` side padding.
- **Page header** (`page-header`): title and subtitle on the left. On the right: on a Dashboard, the dashboard switcher and the Date Range (`header-picker-button`) and "✎ Edit layout" (secondary); on Admin overview, "+ Create block" (primary). In the wizard, the `back-button` sits left of the breadcrumb and title.

**App shell by width.** At 1280px and wider the full sidebar shows. **From 1024 to 1279px the shell uses the icon rail** (`{spacing.icon-rail-width}`), with ☰ opening the full sidebar as a sheet. Below 1024px see EXPERIENCE.md → Responsive & Platform.

**Dashboard grid.**
- `{spacing.grid-columns}` columns with a `{spacing.grid-gap}` gap. The mockup's "8 columns × 360px" (written "8 columns × Medium" in this spec) fits this grid.
- **Width presets** in columns: 3 (KPI card), 4, 6, 8 and 12. [PROPOSED; each value appears in [mockup 02](imports/02-user-overview-add-blocks.png)] Sizes are always written "{columns} columns × {Small | Medium | Large}", for example "6 columns × Medium".
- **Height presets**: Small `{spacing.block-height-small}`, **Medium `{spacing.block-height-medium}`** and Large `{spacing.block-height-large}`, all multiples of `{spacing.grid-row-unit}`. [PROPOSED: Small and Large were TBD for UX in PRD §12.1; Medium comes from the PRD and the mockup]
  - The presets set the **grid** height, not a clipping box. Block content that overflows at 200% text zoom or with text-spacing overrides scrolls inside the Block body, which is then a focusable region labelled with the Block title; content is never clipped. KPI cards grow to fit their content in the narrow and single-column layouts.
  - **Control heights are minimums** (`minHeight` on buttons, inputs and chips); padding grows with the text. No line height or letter spacing is fixed in px.
- **Narrow layout.** When the content area is roughly 720 to 1099px wide (for example with the Add-blocks drawer open), the grid keeps 12 columns: 3-column Blocks stay 4-up and wider Blocks render at 6 columns (2-up), in the same reading order (frame 1 of [mockups/addblocks-hybrid.html](mockups/addblocks-hybrid.html)). The narrow layout is display only; saved positions do not change. [PROPOSED thresholds]
- **Single column.** Below about 720px of content width, Blocks render in one column in the user's order.

**Add-blocks drawer** (`{spacing.drawer-width}`).
- **At about 1024px and wider** it **pushes** the dashboard narrower, and the sidebar collapses to the **icon rail** (T6). At 1280px this leaves about 824px for Blocks.
- **Between 1024 and about 1231px with the drawer open**, the content area is under 720px (viewport − rail 64 − drawer 392 − gutters 56), so the dashboard shows the **single-column** layout in the user's order. The drawer still pushes; the dashboard stays visible and the just-added Block is highlighted in that column. Closing the drawer restores the 12-column layout.
- **Below about 1024px** it becomes a full-height **overlay sheet** (`overlay-sheet`), leaving a `{spacing.drawer-dim-strip-mobile}` strip of dimmed dashboard.

**Create-block wizard.** A content column of form section cards on the left; the sticky live preview pane on the right (steps ① to ③) is `{spacing.preview-pane-width}` in Map Data and wider in steps 1 and 2 (about 45% of the content width, as in [mockup 04](imports/04-create-block-basic-data.png) and [mockup 05](imports/05-create-block-display-behavior.png)).
- **Below 1440px the wizard collapses the sidebar to the icon rail** [DECISION R7]. At 1280px this leaves a Map Data left column of about 752px (1280 − 64 − 56 gutters − 384 preview − 24 gap).
- **Slot rows wrap** [DECISION R7] to `slot-row-wrapped` when the left column is narrower than `{spacing.slot-row-wrap-below}`. At 1024px the left column is about 496px, which fits the wrapped row.
- **Reflow (1.4.10).** Below `{spacing.reflow-stack-below}` of viewport width (including a desktop at 400% zoom): slot rows use `slot-row-stacked`; the expanded slot editor stacks Format, Emphasis and Direction; scalar cards go one per row; transform rows and threshold-band rows stack. **Only the sample-rows table and the JSON view scroll sideways**, inside a focusable region labelled "Sample rows, scrolls sideways". Nothing else scrolls horizontally.

**Sticky layers.** At most the top bar, one sub-bar (the Map Data status bar or the drawer header) and the tablet "Preview" bar are sticky. **When the viewport is under 480 CSS px tall** (for example 1280×1024 at 400%), only the top bar stays sticky; the status bar and the Preview bar scroll with the content. [ASSUMPTION — accepted default: the 480px threshold] Every sticky layer sets `scroll-padding` to its height, so a focused element is never hidden under it.

**Mobile.** `{spacing.page-gutter-mobile}` gutters, a single column, the sidebar becomes a sheet opened from ☰, and there is no horizontal page scroll.

## Elevation & Depth

Depth comes from tone and 1px borders, not shadows. Cards and Blocks sit on `{colors.surface-canvas}` with a `{colors.border-default}` border and **no shadow**. Shadows are used only for things that float above the page, and each is `{colors.shadow-color}` at a set opacity, so a dark theme swaps one token.

- **Toast** (`toast.shadow`): toasts.
- **Float** (`dialog.shadow`, `overlay-sheet.shadow`): dialogs, popovers, the expanded view, the mobile sheet (over the `{colors.scrim}` scrim), and a Block while it is lifted during a drag in Edit Layout Mode.
- **Row** (`drawer-row.openShadow`): the open inline preview in a drawer row.

The desktop drawer is a pushed column, not a floating layer, so it has a left border and no shadow.

## Shapes

Soft but crisp corners:
- `{rounded.xs}`: checkboxes and the top corners of chart bars;
- `{rounded.sm}`: tags, small buttons, role chips, tooltips, kbd keys;
- `{rounded.md}`: inputs, buttons, nav items, icon tiles, thumbnails;
- `{rounded.lg}`: cards, Blocks, drawer rows, toasts;
- `{rounded.xl}`: dialogs;
- `{rounded.sheet}`: the mobile sheet's leading corners.

Pills (`{rounded.full}`) are used only for chips, badges, stepper numerals and avatars. User avatars are circles; Workspace avatars are rounded squares, so the two are never confused.

## Components

Behaviour for each component lives in EXPERIENCE.md → Component Patterns. This section is the visual contract. The colours, type, radii and sizes of every key named here are in the frontmatter `components` block; this section adds placement, content and the rules that tokens cannot express. Short names such as "accent-soft" or "caption text-muted" stand for the matching `colors` and `typography` tokens.

**Buttons and form controls**

| Component | Key | Usage and rules |
|---|---|---|
| Primary | `button-primary` | **One per view**: "Sign in as User →", "+ Add block", "Create block", "Publish block", "Continue to Preview →" |
| Secondary | `button-secondary` | "Save draft", "Preview", "Done" |
| Ghost | `button-ghost` | No border; icon buttons |
| Link | `button-link` | "View all →" |
| Destructive soft | `button-destructive-soft` | "× Remove" in drawer rows (5.91:1) |
| Disabled | `button-disabled` | A disabled primary action always has an adjacent text reason (for example the `required-slot-missing` message, EXPERIENCE.md → Canonical messages). **The reason text is never dimmed**: it is caption text-secondary or error-text at full opacity |
| Input, select | `input`, `select` | Label above in title-sm (weight 600); helper text below in caption text-muted. Placeholders never carry instructions. Required fields mark the label with a red `*`; each form shows "* Required" once at the top in caption text-secondary. On focus, the keyboard `focus-ring` is drawn as well as the focus border and halo |
| Textarea | `textarea` | Vertical resize only. The Paste-JSON `json-textarea` is listed under Map Data parts |
| Endpoint field | `method-prefix` | The "GET" segment joins the path input with a border-control edge |
| Secret field | — | Masked `••••••••`; once saved, "Replace" and no reveal |
| Checkbox | `checkbox` | The same checked treatment everywhere, including sign-in and the wizard's Display & behavior options. Never the browser default |
| Radio | `radio` | Access (All users / Selected groups), the record-path list, the sign-in role cards |
| Switch | `switch` | The Template editor's Mandatory toggle, Keyboard shortcuts and other on/off settings. The state word means colour and position are never the only cue |
| Tooltip | `tooltip` | Appears on hover **and** keyboard focus, can be dismissed with Esc without moving focus, and stays while the pointer is over it (behaviour in EXPERIENCE.md → Small components) |
| Skeleton | `skeleton` | Bars in the shape of the content they stand for. The shimmer stops after 5 seconds in every mode, when "Still loading…" appears in caption text-muted |

**Chips and badges**

| Component | Key | Usage and rules |
|---|---|---|
| Category chip | `chip-category`, `chip-category-active` | On narrow widths chips scroll horizontally with an edge fade; the fade never covers the focused chip |
| Tag | `tag` | The category on drawer rows |
| Type chip | — | number / text / date / array / object. Small, `{rounded.sm}`, in the role-family colours (see Colors) |
| Draft | `badge-draft` | "• Draft" |
| Sample data | `badge-sample-data` | Bold text: "Sample data · used for mapping and preview only" |
| New | `badge-new` | Accent fill |
| Operational | `badge-operational` | With a dot |
| Partial data | `badge-partial-data` | Warning-soft |
| Version | `tag` | "v1.1" in a neutral tag |
| Lock | — | A lock glyph and "Locked" or "Required by your admin" in text-secondary on surface-muted |

**Stepper** (`stepper-step`, `stepper-step-active`, `stepper-step-done`). Five steps: Configure Block → Data Source → Map Data → Preview → Save/Publish. A done numeral shows a white check. The active step sits in an accent-soft pill, its label in accent-ink, not the sketch's #CA8A04 (2.74:1). The upcoming numeral is text-secondary on surface-muted (9.37:1). Steps 1 and 2, when done, collapse into one-line summary cards with a green check and "Edit" (see [mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html)).

**Block card and Block Chrome** (`block-card`, `block-chrome-header`)
- **Header row**: the icon tile (accent-soft, or the category's soft colour), the title, the subtitle, and on the right the refresh ↻, minimize – and more ⋯ controls. A divider sits under the header.
- **Optional parts**: a period selector (a compact select, for example "This year") at the top right of the body; a centred footer action link (`block-footer-link`, "View all approvals →").
- **Minimized**: the header row only.
- **Edit Layout Mode** adds a move handle and a `resize-handle` corner at the bottom right (6.85:1). The page header shows the `edit-layout-toolbar`, ending in **Done**.

**KPI card** (`kpi-card`; inside larger Blocks the Headline value Slot uses `kpi-headline-in-block`)
- A 3-column, Small Block: label, value, then a delta line; an icon tile at the top right.
- **Delta line**: the arrow (↗/↘) and the % in success-text or error, followed by the comparison label in text-muted ("vs last month").
- **Deltas always carry ↗ or ↘**, here and everywhere else (KPI & Chart, Admin overview metric cards, tables). The arrow gives the direction. **Whether the change is good is never shown by colour alone** (FR-29):
  - when Direction is *Lower is better*, the visible delta adds a word after the % (`deltaWord`): "↗ 3.8% · worse" or "↘ 2.1% · better". This is the case where the colour contradicts the usual reading of the arrow;
  - every delta has visually hidden text that states direction and judgement, for example "Up 14.2% from last year, favourable"; the arrow glyph is `aria-hidden`. A flat value reads "No change".

**Block Type faces** ([mockup 02](imports/02-user-overview-add-blocks.png); slot behaviour in EXPERIENCE.md → Map Data). Rows inside a Block are separated by `{colors.surface-muted}` dividers. Any footer action is a centred `block-footer-link` ("View all approvals →", "Open projects →", "View activity log →").

| Face | Keys | Usage and rules |
|---|---|---|
| List | `list-row`, `avatar-initials` | Initials avatar or the mapped icon; right value ("$18,500") and right meta ("Today") |
| Progress list | `progress-row` | Caption example "12/16 tasks"; the % is right-aligned above a full-width track. The fill colour comes from the Block's colour rule, else chart-1. **The % text is the value; the bar is supplementary and `aria-hidden`** (1.39:1 on its track). With threshold bands, the band's icon or label is shown next to the %, so colour is never alone |
| Activity feed | `activity-row` | Actor and action on one line ("Sarah Chen approved Q2 campaign budget"); the relative time under it ("8 min ago") |
| Calendar / Agenda | `calendar-week-strip`, `agenda-row` | A 7-day week strip; **today's** circle is the shape cue, not only the colour. Agenda rows below it ("Meeting room 2A · 45 min") |
| Table / Data grid [CONFIRMED Block Type, FR-32] | `table-header`, `table-row`, `table-cell-numeric`, `table-sort-indicator`, `table-pagination`, `table-row-highlight` | Text columns align left; **numeric columns align right** with tabular numerals; alignment and width come from the Slot settings. The row highlight (band soft fill plus icon and label in the first cell) never relies on the fill alone. Pagination sits at the bottom right. The header row stays visible while the body scrolls inside the Block. At narrow widths the table scrolls sideways inside the Block body, a focusable region labelled with the Block title, with the first column pinned [ASSUMPTION — accepted default: first column pinned] |
| Bar chart [CONFIRMED Block Type, FR-32] | `chart-bar` | Vertical by default; grouped and stacked. Stacked bars show the total above the stack in caption text-secondary, tabular. Gridlines, axes and multi-series encoding follow Chart marks |
| Pie / Donut [CONFIRMED Block Type, FR-32] | `chart-pie` | At most 6 slices; the Admin groups the rest as "Other" (top-N). The donut **centre value** is the Pie centre value Slot (for example the total). **Direct labels** sit beside each slice; when they would collide, the legend lists the name, % and value instead, with slice patterns from series 3 on |
| Text / Status [PROPOSED Block Type, FR-32] | `text-status-face` | **Text**: after 3 lines, an ellipsis, with the full text in About this block. **Status**: the colour rule's matching band ("✓ On track", "⚠ At risk"); the word and icon are always present |
| Initials avatar | `avatar-initials` | The tint is picked deterministically from the name, from five fixed pairs, so a person keeps the same tint everywhere. [ASSUMPTION — accepted default] |

**Chart marks** (`chart-line`, `chart-series-style`, `chart-area`, `chart-gridline`, `chart-axis-label`, `chart-point-highlight`, `chart-point-focus`, `chart-tooltip`, `chart-legend`; [mockup 02](imports/02-user-overview-add-blocks.png) and [mockup 04](imports/04-create-block-basic-data.png))
- **Line**: round joins. A single-series line has no point markers at rest.
- **Two or more series** [DECISION R2]: secondary encoding as in Colors → Chart palette, using `chart-series-style`. [ASSUMPTION — accepted default: the dash/marker order and the 24-point threshold]
- **Area fill** (Area and KPI & Chart): only series 1 gets a fill when there are several series. The fill is supplementary; the line carries the data.
- **Gridlines and axes**: no vertical gridlines, no chart border, no tick marks. Y ticks use the compact format ("$40k").
- **Highlighted point** (the optional *highlighted point* Slot, FR-32, and the hover point). Hover also draws a 1px border-control vertical guide and a `chart-tooltip` that names the series and value.
- **Focused point** (keyboard): the `chart-point-focus` marker, the vertical guide, and the same tooltip as hover.
- **Legend**: under or beside the plot. Bars and slices show a swatch with their fill pattern.

**Wizard page parts** ([mockup 04](imports/04-create-block-basic-data.png) and [mockup 05](imports/05-create-block-display-behavior.png))

| Component | Key | Usage and content |
|---|---|---|
| Back button | `back-button` | ←, left of the breadcrumb "Block management / Create block" and the title |
| Form section card | `form-section-card` | Step ①: "Basic information · Give your block a clear identity." and "Display & behavior · Set the default dimensions and controls."; step ②: "Data configuration · Connect the block to a trusted data source." |
| Live preview pane | `preview-pane` | Header "Live preview" with "Changes appear here automatically.", and the Desktop/Tablet/Mobile `segmented-control` on the right. The size label ("8 columns × Medium (360px)") is a pill centred above the Block |
| Version-safe publishing callout | `callout-version-safe` | Under the preview canvas in steps ① to ③, repeated in the step ⑤ summary. Title "Version-safe publishing"; body "Publishing creates version 1.0. Future edits create a new version, so existing user layouts remain stable." The version number is the one the publish will create |

**Slot row** (Map Data checklist; `slot-row`, `slot-row-wrapped`, `slot-row-stacked`)
- **Columns**: status dot, slot name with type hint, mapped field (role chip plus mono path), presentation summary, confidence or status pill, and the action "Change field ⌄".
- **Wrapping** [DECISION R7]: `slot-row-wrapped` below `{spacing.slot-row-wrap-below}` of left-column width; `slot-row-stacked` below `{spacing.reflow-stack-below}` of viewport width.
- Rows are grouped under label-caps headers (HEADER, KPI, CHART, META). A required Slot shows a red `*`.
- Captions and type hints inside error-soft rows follow *Text on tinted fills* (Colors).

| State | Status dot | Pill | Row treatment |
|---|---|---|---|
| Auto-mapped · High | ✓ on success-soft | `confidence-high` "High" with a full meter | Plain. Auto-accepted, so it counts as Confirmed |
| Auto-mapped · Medium | ✓ on success-soft | `confidence-medium` "Medium" with a ⅔ meter | Plain |
| Auto-mapped · Low | ? on warning-soft | `confidence-low` "Low confidence — please review" | Pinned to the top of the list; caption reason in warning |
| Confirmed | ✓ on success | "Confirmed" success-soft pill | Plain. Shows "Confirmed by you" on hover |
| Overridden | ✎ on info-soft | "Overridden" in info-strong on info-soft | Plain. Inline note when the override changed a role |
| Optional-empty | dashed empty circle | `slot-pill-optional`: "Optional" | Plain; field column reads "—" |
| Required-missing | ! on error | `slot-pill-error`: "Required missing" | `slot-row-required-missing` |
| Type-mismatch | ! on error | `slot-pill-error`: "Type mismatch" | `slot-row-type-mismatch`, plus a one-line reason ("Text field can't be a numeric headline") |
| Expanded | — | — | `slot-row-expanded`; three-column editor (Format · Emphasis · Direction), one column below `{spacing.reflow-stack-below}` |

"Confirmed by you" is visible caption text under the pill, not a hover-only tooltip.

**Role chips** (`role-chip`, `role-chip-label`, `role-chip-value`, `role-chip-dimension`, `role-chip-measure`, `role-chip-time`, `role-chip-filter`, `role-chip-none`)
- Label, Value, Dimension, Measure, Time and Filter/Category, each binding its role triple (text, fill, border). `role-chip-none` means no role.
- As a picker, the chip shows a ⌄ caret.
- **A flagged chip** (a suspected wrong role) adds a leading "!" in error. A colour change is never the only signal.
- Role chips always show their names. Measure and Time are near-identical under deuteranopia, so a role is never shown as a bare colour swatch (not in the status meter, not in a legend).

**Map Data parts** (behaviour: EXPERIENCE.md → Map Data; layout reference: [mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html))

Keys: `mapdata-status-bar`, `status-meter`, `scalar-card`, `roles-table`, `roles-summary-line`, `suggestion-row`, `record-path-list`, `aggregate-control` (above the sample table), `transform-row`, `comparison-editor` (inside the expanded Comparison slot row), `threshold-band-row`, `parameters-table`, `json-textarea`, `fetch-error-card`, `missing-slot-placeholder`, `source-legend` (under the preview Block). Rules beyond the tokens:
- **Status bar**: the meter is decorative (`aria-hidden`); the summary text carries the meaning ("Auto-mapped 6 of 9 · 2 confirmed · 1 required missing"). Then the Sample data badge, "↻ Re-run auto-map" and **Continue to Preview →**, with its reason inline when disabled.
- **Scalar card** qualifiers: "· main", "· baseline", "· unit", "· as-of".
- **Roles table**: its sideways scroll is the only one in the step.
- **Suggestion row** actions: accept ("Set as Measure") and dismiss ("Keep as Filter").
- **Transform** stage labels: FILTER, CALCULATE, GROUP, AGGREGATE, SORT, TOP-N.
- **Paste-JSON** invalid result states the line and column and offers a "Go to line 4" link button.
- **Missing-slot placeholder**: in the live preview, where the missing part would render. The label plate keeps its text ("Chart series required · No Measure mapped · click to choose") legible over the hatching. It takes the standard focus ring.
- **Source legend** rows read, for example, "Value total → $284,680".

**Add-blocks drawer row** (`drawer-row`, `drawer-thumbnail`, `drawer-thumbnail-mobile`)
- **Layout**: thumbnail, name in title-sm, a description (2 lines on desktop, 1 on mobile), the category tag and an optional "New" badge, and the action on the right.
- **Actions** (each at least `{spacing.target-chrome}` tall; `{spacing.target-touch}` on touch):
  - "+ Add": accent-ink text, white fill, accent-border border;
  - "✓ Added" (`drawer-row.addedStatus`): **status text, not a control**. It stays visible;
  - "× Remove": a separate `button-destructive-soft` **beside** "✓ Added". It is shown on row hover, on focus within the row and on touch layouts, and is always in the accessibility tree, so tabbing to it makes it visible;
  - "Locked": the lock badge.
- **Keyboard focus** is the focus ring on the focused button.
- **Thumbnail**: a decorative schematic of the Block shape (KPI, chart, list or progress) drawn in the chart palette on white.
- **No drag grips.** The mockup's ⋮⋮ grips on drawer rows are removed, because Blocks are never dragged from the drawer (A4).
- **Inline preview**: renders the real Block at about 340px wide, with a "Sample data" label and a meta line in caption on surface-sunken.

**Toast** (`toast`)
- An 18px success check circle on the left, the message in body-sm, actions ("Show me", "Undo") in accent-ink-inverse (13.46:1), bold and underlined, and the × close button.
- **Error toasts** use an error icon instead of the check; they do not auto-dismiss.
- **Position**: the top right of the dashboard area, never over the drawer (T9). On mobile it sits inside the sheet, above its footer, and never covers the focused element.

**Feedback marks on the dashboard**
- **Just-added outline** (`just-added-outline`): the outline and halo, plus a "Just added" pill (accent fill, on-accent, 11px bold) on the top-left edge. The halo follows a Workspace accent override. Under reduced motion the halo does not fade; the static outline shows for 3 seconds and is then removed.
- **Free-space hint** (`free-space-hint`): "The next block you add goes here."
- **Edge pill**: an accent pill, "↓ 1 new block below", at the edge of the dashboard viewport.

**Top bar and page-header controls** ([mockup 02](imports/02-user-overview-add-blocks.png))
- **Settings gear** (`icon-button-topbar`): between notifications and the primary action, with a tooltip ("Settings").
- **Dashboard switcher** and **Date Range** (`header-picker-button`): a grid glyph and a calendar glyph; current values such as "Grid dashboard" and "May 1 – May 31" (tabular). They open popovers on the dialog surface at `{rounded.lg}`.
- **Pause live updates** (`live-updates-toggle`) [DECISION R4]: in the page header next to the Date Range, shown whenever a Block on the dashboard refreshes automatically. While paused the header freshness line shows `pausedLabel`.

**Admin overview** ([mockup 03](imports/03-admin-overview.png))
- **Metric cards** (`admin-metric-card`): four in a row, each with its icon tile at the top left. **The delta always starts with ↗ or ↘ and a sign** ("↗ +4 this month", "↗ +12 this week"), in success-text when good, error when bad, text-muted when flat, with the same hidden judgement text and `deltaWord` rule as the KPI card. A share that is not a change ("92% active this week") is plain text-muted with no arrow. The mockup's arrow-less green captions are replaced.
- **Panels** ("Recent block activity", "Platform health"): white cards with a title-md title, a caption text-muted subtitle, and a "View all →" `button-link` or the overall status badge on the right.
- **Recent block activity rows** (`activity-admin-row`): the meta reads "Published by Olivia Martin" or "Draft by Maya Patel"; the time on the right reads "2 hours ago", "Yesterday" or "May 18, 2026".
- **Platform health rows** (`health-row`): status words Operational / Degraded / Outage; for Data Sources, Healthy / Degraded / Unreachable plus "Last success 10:42". The dot never appears without its word. The overall badge uses `badge-operational`, or warning-soft / error-soft with the word Degraded / Outage.

**Sidebar and icon rail** (`sidebar`, `icon-rail`, `sidebar-nav-item-active`, `workspace-switcher`)
- **Logo row**: 64px tall; a 28px logo tile with "Dashflow" in title-md bold.
- **Workspace switcher card**: the avatar tile with the initials ("GI"), the name in title-sm, the label in caption, and a › chevron.
- **Nav items**: 16px icon, title-sm in text-secondary. The active item follows *Selected states* (Colors).
- **Footer**: Help & support, Sign out, Appearance, then the user row (avatar circle, name, role, ⋯).
- **Icon rail**: the same items as 20px icons with tooltips; the active item keeps its accent-soft tile and leading bar; the workspace switcher becomes the avatar tile only.

**Sign-in hero** (`signin-hero`, `signin-role-card`, `signin-role-card-selected`; [mockup 01](imports/01-sign-in.png))
- **Split screen**: about 55% dark hero on the left, the white form on the right.
- **Hero**, top to bottom: the logo and "Dashflow" in hero-text, with "Dashboard Management System" in hero-text-muted at the top right; a tagline pill with a sparkle glyph, "One workspace. Every insight." (13.01:1); the headline and body; an abstract dashboard illustration (hero-card panels with accent bars); two trust lines with accent icons, "Enterprise-grade security" and "Live data updates"; faint concentric circles in hero-surface-raised as background; and the footer "© 2026 Dashflow. All rights reserved." at the bottom left in caption hero-text-muted.
- **Form**, top to bottom:
  - the eyebrow "WELCOME BACK" in label-caps accent-ink, the title in headline-lg, and the subtitle "Choose your workspace role and enter your credentials." in body-sm text-muted;
  - two role cards (User · Personal workspace / Admin · System management), each with a visible radio at the top right; the unselected card has an empty radio;
  - the email and password inputs with leading icons and a show/hide eye (a button named "Show password");
  - "Remember me" and "Forgot password?" (accent-ink). The Remember me checkbox uses the `checkbox` component, **not** the browser-default blue in [mockup 01](imports/01-sign-in.png);
  - the full-width primary button;
  - a "Secure workspace access" divider and the help line.
- **Below about 1024px** the hero is hidden and the form is centred, with the logo above it. [ASSUMPTION]

**Dialog** (`dialog`): title in title-md, actions right-aligned (primary last). Used for the publish impact confirmation, the session-timeout warning and destructive confirmations (unpublish, archive, delete dashboard, reset to template).
- A destructive confirm button (`button-destructive`) uses an error fill with on-status text instead of the accent (4.83:1) [ASSUMPTION — accepted default]. It repeats the object ("Reset Finance weekly – Jamie"); Cancel sits to its left and receives initial focus (EXPERIENCE.md → Interaction Primitives).
- In the publish impact dialog, the diff uses before → after rows (before in text-muted with a strikethrough, after in text-primary), and impact numbers are bold with tabular numerals.

**Popovers** share the dialog surface at `{rounded.lg}` and the float elevation. In every popover list (palette results, menus, the field tree, the dashboard switcher, the workspace list, role menus) the **keyboard-active option** uses `listbox-option-active` and the **chosen value** uses `listbox-option-selected`.

| Popover | Width | Content and rules |
|---|---|---|
| Command palette (⌘K) | `{spacing.popover-width-palette}` | Search input at the top; results in groups under label-caps headers. While results load, two skeleton rows per group |
| Notifications panel | `{spacing.popover-width-notifications}` | Rows show a 28px icon tile, a body-sm sentence and a caption time. Unread rows have a 6px accent dot **and** bold text. The bell badge is an error-filled pill with an on-status count. Empty: a centred caption in text-muted |
| Workspace switcher list | — | Rows with an avatar tile, name, label and a role tag. The current Workspace shows a ✓ |
| Field picker / field tree | `{spacing.popover-width-picker}` | Search at the top. Disabled fields are in text-muted, with the reason in caption. Each tree row has an indent guide in border-default, a type chip and the example value in mono text-muted. The picked leaf uses `listbox-option-selected`; the visual "Use" pill is decorative |
| Role menu | `{spacing.popover-width-picker}` | Each role item shows its chip and a one-line description in caption text-secondary |

**Generic admin and settings parts.** `data-table` is the list page for every Admin list (Block management, Draft blocks, Published blocks, Data sources, Audit log, User configuration, Version history, Block categories, Dashboard templates) and My dashboards; a selected or just-saved row uses `rowSelected`. `date-range-calendar` is the Custom option of the Date Range popover. `icon-picker` marks the chosen icon with `tileSelected`. `expanded-view` also serves **"View as table"** for chart Blocks, with only the table. `skip-link` is a pill.

**Banners** are full-width bars at the top of the content area, in body-sm with an icon and a word.

| Banner | Treatment |
|---|---|
| Reconnecting | info-soft with info-strong text |
| Offline while editing (wizard and forms) | info-soft with info-strong text |
| API changed (in the wizard) | warning-soft with a 3px warning left rule |
| Live shape mismatch | error-soft with error-text |
| "Map Data works best on a larger screen" (below 640px) | info-soft with info-strong text and a × Dismiss button (`banner-dismissible`). It never blocks the step |

**Block state faces** (visuals; behaviour lives in EXPERIENCE.md)

| State | Visual |
|---|---|
| Loading | `skeleton` bars, shown only on a cold load or when the Block has no prior data |
| Stale | Values recoloured to text-muted, with a clock icon and the "Stale: last data 10:42" caption in warning |
| Unavailable | A ⚠ icon and the `unavailable-user` message (EXPERIENCE.md → Canonical messages) in text-secondary where the value would be. Never a zero |
| Paused (R4) | Values unchanged; the header freshness line reads "Paused · data as of 10:42" in caption text-secondary. No per-Block styling change |
| Error | An error icon, a body-sm message and a Retry link |
| Partial data | A `badge-partial-data` badge in the header |
| Empty | A centred caption in text-muted |

## Do's and Don'ts

| Do | Don't |
|---|---|
| Use `{colors.accent}` for fills: primary buttons, active and selected states, highlights | Set any text in the accent or accent-border; use `{colors.accent-ink}` for accent text on light surfaces and `{colors.accent-ink-inverse}` on dark ones |
| Give every form control a `{colors.border-control}` boundary | Use `{colors.border-strong}` (1.47:1) as the only edge of an input, checkbox or radio |
| Add a ≥ 3:1 cue (bar, border, ✓, radio, inset ring) to every selected or active state | Rely on the accent-soft fill (1.07:1) alone |
| Draw series-1 lines and points in `{colors.chart-1-stroke}`; give charts with 2+ series direct labels or dash/marker variants | Draw a yellow line on white, or identify a series by colour alone |
| Keep one primary (yellow) button per view | Put two yellow buttons side by side |
| Reference semantic tokens in every component | Hard-code hex or `rgba()` values, which would block the dark theme and Workspace branding |
| Let Workspaces override only brand tokens (accent, logo) | Let branding change status colours, text or borders |
| Use tabular numerals for every figure | Use proportional digits in KPIs or tables |
| Pair every status colour with an icon, arrow or word | Signal state, delta direction or a chart series by colour alone |
| Use at most 6 chart series, in palette order | Generate extra colours; group or apply top-N instead |
| Show "Unavailable", "Stale" and "Sample data" in words | Show $0, a blank or a spinner forever when a value can't be computed |
| Separate cards with borders and tone | Add shadows to cards and Blocks at rest |
| Use the blue focus ring for keyboard focus | Reuse yellow for focus; it would read as selection |
| Keep the sign-in hero as the only dark surface in the MVP | Introduce partial dark panels before the dark theme exists |
