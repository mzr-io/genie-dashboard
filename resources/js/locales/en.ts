// Canonical message catalogue (Story 1.7, UX-DR-282): the single source of product copy.
// Exactly the 89 keys of EXPERIENCE.md > Voice and Tone > Canonical messages. Example values are
// replaced by named parameters, so no real block, time or count is written here. Keys with
// several texts are objects of named sub-messages; keys with a screen-reader form carry it as
// an `announce` sub-key. Control labels that are not canonical messages live in ./labels.ts.
const en = {
    'field-error': "Enter an email address, like name{'@'}company.com.",
    'signin-role-denied':
        "You don't have admin access in this workspace. Sign in as User instead?",
    'signin-failed':
        "That email and password don't match. Try again, or reset your password.",
    throttled: 'Too many attempts. Wait a minute, then try again.',
    'fetch-failed':
        "We couldn't reach {source}. Check the endpoint or try again. ▸ Technical details",
    'json-invalid':
        "This isn't valid JSON. Line {line} has {problem}. Go to line {line}",
    'json-valid':
        '✓ Valid JSON · {count} records found at {path} · Pasted {time} · {bytes} bytes · {shape}',
    'test-ok': '✓ Connected · {status} · {ms} ms',
    'notify-mapping-failed': "{block} can't read {field}. Fix mapping →",
    'notify-version': '{block} was updated to v{version}',
    'fetch-ok': '✓ {status} · {ms} ms · {count} records found at {path}',
    'required-slot-missing': {
        one: 'Map {slot} to continue. Optional slots can stay empty.',
        many: 'Map {count} required slots to continue: {slots}. Optional slots can stay empty.',
    },
    'post-readonly': {
        label: 'This POST is a read-only query',
        hint: 'Dashflow never sends requests that change data. Mark this only if the endpoint just reads.',
    },
    'url-invalid': 'Use a link that starts with https:// or http://.',
    'group-required':
        'Choose at least one group, or make it available to all users.',
    'access-impact': {
        message: '{count} will lose this block.',
        count: '{count} user | {count} users',
    },
    'api-changed':
        'API response changed: field {field} no longer present → {slot}: Unavailable',
    'api-match-found':
        'Dashflow found a new number field {field} that looks like the same data (matching value {value}). [Use {field}]',
    'live-shape-unreachable':
        "We couldn't reach the API to check this block. Save a draft and try again.",
    'drawer-empty-search':
        "No blocks match '{query}'. Ask your admin to publish one.",
    'drawer-none':
        "No blocks are available yet. Your admin hasn't published any.",
    'drawer-load-failed': "We couldn't load the blocks. Try again.",
    'dashboard-empty': 'This dashboard is empty. Add blocks to start.',
    'dashboard-load-failed':
        "We couldn't load this dashboard. Your layout is safe. Try again.",
    'block-empty': 'No data for this period.',
    'block-error': "We couldn't load this block. Try again",
    'sample-wizard': 'Sample data · used for mapping and preview only',
    'sample-drawer':
        "Sample data. Your dashboard will show your organisation's figures.",
    'sample-note':
        "Sample data only. Published blocks call {endpoint} live. Before you publish, Dashflow checks that the live response matches this sample's shape.",
    'toast-add': {
        visible: '{block} added · Show me · Undo',
        announce: '{block} added next to {neighbour}. Undo with {shortcut}.',
    },
    'toast-add-below': {
        visible: '{block} added below · Show me ↓ · Undo',
        announce:
            "{block} added next to {neighbour}. Undo with {shortcut}. It's below the visible area.",
    },
    'toast-remove': {
        visible: '{block} removed · Undo',
        announce: '{block} removed. Undo with {shortcut}.',
    },
    'toast-rollback':
        "We couldn't add {block}. Your dashboard is unchanged. Try again.",
    'edge-pill': '↓ {count} new block below | ↓ {count} new blocks below',
    'free-space-hint': 'The next block you add goes here.',
    'drawer-footer': 'New blocks go to the first free space.',
    'unavailable-user': "Unavailable — this value can't be shown right now.",
    'unavailable-admin': 'Unavailable: {field} missing',
    stale: {
        today: 'Stale: last data {time}',
        earlier: 'Stale: last data {day} {time}',
        announce: 'Block data is stale. Last data {when}.',
    },
    'stale-aggregate': {
        announce:
            '{count} block is stale. Last data {time}. | {count} blocks are stale. Last data {time}.',
        recovered: 'Blocks are up to date.',
    },
    paused: 'Paused · data as of {time}',
    reconnecting:
        "Reconnecting… Your dashboard will refresh when you're back online.",
    'back-online': 'Back online. Refreshing your dashboard.',
    'refresh-limited': 'Refreshed a moment ago',
    'block-unpublished':
        'This block is no longer available. You can remove it.',
    'block-access-removed': 'This block is no longer available to you.',
    locked: 'Required by your admin',
    'perm-publish': "You don't have permission to publish this block.",
    'perm-denied':
        "You don't have permission to view this. Ask a workspace admin.",
    'publish-impact':
        "{users} users have this block and {templates} templates include it. Their layouts won't move.",
    'publish-success':
        '{block} v{version} is live in the Block Library under {category}.',
    'version-safe':
        'Publishing creates version {version}. Future edits create a new version, so existing user layouts remain stable.',
    'host-not-allowlisted':
        "This host isn't on your workspace allowlist. Add it in System settings, or ask a platform operator for a private-network address. ▸ Technical details",
    'blocked-address':
        "Dashflow can't call loopback, link-local or cloud-metadata addresses. Use the API's public or allowlisted host.",
    'response-too-large':
        "Response too large: {size} is over this data source's {limit} limit. Narrow the request with parameters, or raise the limit on the data source. Nothing was shown, so no totals are wrong.",
    'not-json':
        'This endpoint returned HTML, not JSON. Dashflow supports REST APIs that return JSON.',
    'live-not-supported':
        "Live isn't available: {source} isn't marked as supporting Live refresh.",
    'datasource-none': {
        message: 'No data sources are registered yet.',
        register: '+ Register data source',
        ask: 'Ask an admin who manages data sources to register one.',
    },
    'reset-requested':
        "Check your email. If an account exists for that address, we've sent a link to reset your password.",
    'reset-expired':
        'This reset link has expired or was already used. Request a new one.',
    'password-changed':
        'Your password was changed. Sign in with your new password.',
    'restore-done':
        'Draft v{draft} created from v{source}. Publish it to make it live.',
    'restore-replace': 'Replace your current draft with v{version}?',
    'unpublish-impact':
        "{users} users have this block. They'll see 'This block is no longer available.' It leaves the Add-blocks Panel and search.",
    'reset-template':
        "Reset {dashboard} to the template's current layout? Your blocks, positions and sizes on this dashboard will be replaced. This can't be undone.",
    'overview-delete':
        "{dashboard} is your default dashboard and can't be deleted.",
    'mandatory-added':
        "Your admin added {block} to {dashboard}. It's at the end of your dashboard.",
    'template-block-omitted':
        "{count} block in this template isn't available to you. | {count} blocks in this template aren't available to you.",
    'templates-none': 'No templates are available to you yet.',
    'search-empty': 'No matches in {workspace}. Try a block or dashboard name.',
    'notifications-empty':
        'No notifications yet. Updates to your blocks and dashboards appear here.',
    'expanded-empty': 'No rows for this period.',
    'expanded-error': "We couldn't load the rows. Try again.",
    'workspace-role': "You're a User in {workspace}.",
    'layout-save-failed': "We couldn't save your layout. Try again.",
    'save-failed': {
        draft: "We couldn't save your draft. Your changes are still here. Try again.",
        form: "We couldn't save your changes. They're still here. Try again.",
    },
    'offline-editing':
        "You're offline. Your changes are still here. Save draft and Publish come back when you reconnect.",
    'session-warning': {
        visible: "You'll be signed out in {time} for security.",
        announce:
            "You'll be signed out in {time}. Stay signed in to keep working.",
    },
    'session-expired':
        "You were signed out to protect your workspace. Your draft was saved, and we've restored it.",
    'draft-locked':
        '{user} is editing this draft (since {time}). You can view it, or take over editing.',
    'draft-taken-over':
        '{user} took over editing at {time}. Your changes were saved.',
    'map-small-screen':
        'Map Data works best on a larger screen. Everything still works here.',
    'list-empty': 'No {items} yet. {action} to start.',
    'list-no-match': "No {items} match '{query}'.",
    saved: 'Changes saved.',
    'unsaved-changes': 'You have unsaved changes.',
    'wizard-subtitles': {
        identity: 'Give your block a clear identity.',
        source: 'Connect the block to a trusted data source.',
        display: 'Set the default dimensions and controls.',
    },
    'page-subtitles': {
        overview: "Here's what's happening across your workspace today.",
        admin: 'Monitor dashboard adoption, publishing activity and platform health.',
    },
    'signin-subtitle': 'Choose your workspace role and enter your credentials.',
} as const;

export type Catalogue = typeof en;
export type CatalogueKey = keyof Catalogue;

export default en;
