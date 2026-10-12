// Control labels that are not canonical messages (Story 1.7). They are local to the components
// that render them, so the catalogue keeps exactly the 89 canonical keys.
export const technicalDetailsLabels = {
    title: 'Technical details',
    status: 'HTTP status',
    path: 'Field path',
    host: 'Host',
    reason: 'Reason code',
    requestId: 'Request ID',
    copy: 'Copy request ID',
    copied: 'Copied',
    copyFailed: 'Copy failed. Select the request ID to copy it.',
} as const;

// Labels of the shared form controls and status components (Story 1.8). They are control
// vocabulary, not canonical messages, so they live here beside the other control labels.
export const controlLabels = {
    required: 'Required',
    errorPrefix: 'Error:',
    errorSummaryTitle: 'Fix these fields to continue',
    secretMask: '••••••••',
    secretSetShort: (date: string) => `set ${date}`,
    secretSetOn: (date: string) => `Secret set on ${date}`,
    replace: 'Replace',
    replaceToken: 'Replace token',
    secretRequired: 'Enter a new value to replace the saved one.',
    on: 'On',
    off: 'Off',
    stillLoading: 'Still loading…',
    locked: 'Locked',
    lockedAnnounce: 'required by your admin and cannot be removed',
    sortBy: (column: string) => `Sort by ${column}`,
} as const;

// Labels of the kpi-card Block Type's renderer (Story 3.2). The canonical catalogue is closed, so the card's own copy lives here.
export const kpiCardLabels = {
    fallbackTitle: 'KPI card',
    noChange: 'No change',
    better: '· better',
    worse: '· worse',
    empty: 'No data to show yet.',
    errorMessage: "This block couldn't load.",
    headlineField: 'Headline value',
    up: 'Up',
    down: 'Down',
    flat: 'No change',
    betterSpoken: 'better',
    worseSpoken: 'worse',
    favourableSpoken: 'favourable',
    unfavourableSpoken: 'unfavourable',
    staleGeneric: 'Stale: last update time unknown',
    // "Up 12.5% vs last month, better"
    deltaSpoken: (
        direction: string,
        value: string,
        label: string | null,
        judgement: string | null,
    ) =>
        [
            value === '' ? direction : `${direction} ${value}`,
            label ? ` ${label}` : '',
            judgement ? `, ${judgement}` : '',
        ].join(''),
} as const;

// Labels of dialogs, toasts, banners and landmarks (Story 1.9). Buttons repeat across screens,
// so they live here once.
export const overlayLabels = {
    cancel: 'Cancel',
    save: 'Save',
    discardChanges: 'Discard changes',
    keepEditing: 'Keep editing',
    dismiss: 'Dismiss',
    dismissNotification: 'Dismiss notification',
    undo: 'Undo',
    showMe: 'Show me',
    close: 'Close',
    notifications: 'Notifications',
    skipLinks: 'Skip links',
    skipToContent: 'Skip to content',
    goToNotifications: 'Go to notifications',
    bannerInfo: 'Info',
    bannerWarning: 'Warning',
    bannerError: 'Error',
    errorToast: 'Error',
    successToast: 'Success',
    unsavedTitle: 'Unsaved changes',
    deleteAction: (object: string) => `Delete ${object}`,
} as const;

// Labels of the invitation-accept page (Story 1.12). Its errors come from the catalogue
// (`field-error`, `reset-expired`) or from the server's validation messages.
export const invitationLabels = {
    title: 'Set up your account',
    description: 'Choose your name and password to join as Admin.',
    name: 'Full name',
    email: 'Email',
    emailHelper: 'The address this invitation was sent to.',
    password: 'Password',
    passwordConfirmation: 'Confirm password',
    submit: 'Create account',
    expiredTitle: 'Link unavailable',
    expiredDescription: 'This invitation link cannot be used.',
    goToSignIn: 'Go to sign in',
    nameRequired: 'Enter your full name.',
    passwordRequired: 'Enter a password.',
    passwordMismatch: 'The two passwords must match.',
    submitFailed:
        "We couldn't submit this. Check your connection, reload the page and try again.",
} as const;

// Labels of the sign-in page and its hero (Story 1.13; UX-DR-90, 91, 212). Messages shown after a
// failed attempt (`signin-failed`, `signin-role-denied`, `throttled`) come from the catalogue.
export const signInLabels = {
    product: 'Dashflow',
    productDescriptor: 'Dashboard Management System',
    tagline: 'One workspace. Every insight.',
    headline: 'Turn complex work into clear decisions.',
    heroBody:
        'Bring your data together, build blocks and share dashboards with your team.',
    trustSecurity: 'Enterprise-grade security',
    trustLive: 'Live data updates',
    footer: '© 2026 Dashflow. All rights reserved.',
    heroIllustration: 'Illustration of a dashboard',
    eyebrow: 'WELCOME BACK',
    title: 'Sign in to your workspace',
    roleGroup: 'Sign in as',
    roleUser: 'User',
    roleUserHint: 'Personal workspace',
    roleAdmin: 'Admin',
    roleAdminHint: 'System management',
    email: 'Email',
    emailPlaceholder: 'name@company.com',
    password: 'Password',
    passwordRequired: 'Enter your password.',
    remember: 'Remember me',
    forgot: 'Forgot password?',
    submit: (role: string) => `Sign in as ${role}`,
    divider: 'Secure workspace access',
    help: 'Contact your workspace administrator',
    summaryRole: 'Role',
    submitFailed:
        "We couldn't sign you in. Check your connection, reload the page and try again.",
} as const;

// Labels of the Forgot password and Reset password pages (Story 1.14). Their messages
// (`reset-requested`, `reset-expired`, `field-error`, `throttled`) come from the catalogue.
export const passwordResetLabels = {
    forgotTitle: 'Forgot password?',
    forgotDescription:
        "Enter your email and we'll send you a link to reset it.",
    email: 'Email',
    emailPlaceholder: 'name@company.com',
    sendLink: 'Send reset link',
    backToSignIn: 'Back to sign in',
    resetTitle: 'Reset password',
    resetDescription: 'Choose a new password for your account.',
    expiredTitle: 'Link unavailable',
    expiredDescription: 'This reset link cannot be used.',
    requestNew: 'Request a new link',
    password: 'New password',
    passwordConfirmation: 'Confirm new password',
    passwordRequired: 'Enter a new password.',
    passwordMismatch: 'The two passwords must match.',
    submit: 'Reset password',
    summaryTitle: 'Fix these fields to continue',
    submitFailed:
        "We couldn't submit this. Check your connection, reload the page and try again.",
} as const;

// Labels of Help & support (Story 1.18). The empty
// list is `msg:list-empty`; the contact line repeats the sign-in page's wording.
export const helpLabels = {
    title: 'Help & support',
    linksHeading: 'Help links',
    contact: 'Contact your workspace administrator',
    contactHeading: 'Contact',
    opensInNewTab: '(opens in a new tab)',
} as const;

// Labels of Profile & settings (Story 1.18; UX-DR-23, 169, 263, 269). The save messages are `msg:saved` and
// `msg:save-failed` (form wording); the Locale and Time zone lists show the identifiers the server accepts.
export const profileLabels = {
    profileHeading: 'Profile',
    avatar: 'Profile picture',
    avatarHelper: 'A PNG, JPEG or WebP image.',
    avatarCurrent: (name: string) => `Current profile picture of ${name}`,
    avatarWrongType: 'Choose a PNG, JPEG or WebP image.',
    avatarTooLarge: (limit: string) =>
        `Choose an image no larger than ${limit}.`,
    avatarTooLargeGeneric: 'Choose a smaller image.',
    name: 'Full name',
    nameRequired: 'Enter your full name.',
    locale: 'Locale',
    timezone: 'Time zone',
    formatPreview: 'Preview',
    formatPreviewNumber: 'Number',
    formatPreviewCurrency: 'Currency',
    formatPreviewDate: 'Date and time',
    shortcuts: 'Keyboard shortcuts',
    shortcutsHelper:
        'Off turns the single-key shortcuts off. Shortcuts with Ctrl or Cmd, and the arrow, Enter, Space, Esc and Tab keys, keep working.',
    shortcutList: [
        { key: '/', does: "Focus the drawer's search" },
        { key: 'A', does: 'Add the focused drawer row' },
        { key: 'M', does: 'Pick up the focused Move button' },
    ],
    save: 'Save',
    passwordHeading: 'Change password',
    passwordDescription:
        'Enter your current password and choose a new one. Your other sessions are signed out.',
    currentPassword: 'Current password',
    currentPasswordRequired: 'Enter your current password.',
    newPassword: 'New password',
    newPasswordRequired: 'Enter a new password.',
    passwordConfirmation: 'Confirm new password',
    passwordMismatch: 'The two passwords must match.',
    passwordSubmit: 'Change password',
    passwordChanged:
        'Your password was changed. Your other sessions were signed out.',
} as const;

// The pages of the two-area shell (Story 1.16): the navigation title, what the page lists and the action
// that fills it. `msg:list-empty` is "No {items} yet. {action} to start."; the Overview and Admin overview
// subtitles come from `msg:page-subtitles`. Titles are shared by the sidebar, the breadcrumb and the page.
export const shellPages = {
    overview: {
        title: 'Overview',
        items: 'dashboards',
        action: 'Create a dashboard',
    },
    'my-dashboards': {
        title: 'My dashboards',
        items: 'dashboards',
        action: 'Create a dashboard',
    },
    templates: {
        title: 'Templates',
        items: 'templates',
        action: 'Use a template',
    },
    profile: {
        title: 'Profile & settings',
        items: 'settings',
        action: 'Update your profile',
    },
    help: {
        title: 'Help & support',
        items: 'help topics',
        action: 'Ask your workspace admin',
    },
    'admin-overview': {
        title: 'Admin overview',
        items: 'activity',
        action: 'Create a block',
    },
    'block-management': {
        title: 'Block management',
        items: 'blocks',
        action: 'Create a block',
    },
    'create-block': {
        title: 'Create block',
        items: 'blocks',
        action: 'Start a block',
    },
    'draft-blocks': {
        title: 'Draft blocks',
        items: 'draft blocks',
        action: 'Create a block',
    },
    'published-blocks': {
        title: 'Published blocks',
        items: 'published blocks',
        action: 'Publish a block',
    },
    'block-categories': {
        title: 'Block categories',
        items: 'block categories',
        action: 'Add a category',
    },
    'dashboard-templates': {
        title: 'Dashboard templates',
        items: 'dashboard templates',
        action: 'Create a template',
    },
    'data-sources': {
        title: 'Data sources',
        items: 'data sources',
        action: 'Register a data source',
    },
    'user-configuration': {
        title: 'User configuration',
        items: 'users',
        action: 'Invite a user',
    },
    'system-settings': {
        title: 'System settings',
        items: 'settings',
        action: 'Change a setting',
    },
    'audit-log': {
        title: 'Audit log',
        items: 'audit events',
        action: 'Make a change',
    },
} as const;

export type ShellPageKey = keyof typeof shellPages;

// The Admin overview's "Platform health" panel (Story 2.18; UX-DR-114, 260): only the "Your data sources" part. The platform services part is a later story.
export const platformHealthLabels = {
    panel: 'Platform health',
    sourcesTitle: 'Your data sources',
    empty: 'No data sources yet.',
    failed: "We couldn't load the health of your data sources.",
    retry: 'Retry',
    loading: 'Loading data sources',
    // The row's accessible name: "{name}, {word}, last success {time}", or the no-call text when there was no success.
    row: (name: string, word: string, time: string | null, noCall: string) =>
        `${name}, ${word}, ${time === null ? noCall : `last success ${time}`}`,
};

// Labels of the shell: sidebar, icon rail, top bar and profile menu (Story 1.16; UX-DR-79..86, 160, 270).
export const shellLabels = {
    product: 'Dashflow',
    sectionUser: 'WORKSPACE',
    sectionAdmin: 'ADMINISTRATION',
    navigation: 'Main navigation',
    workspace: 'Workspace',
    signOut: 'Sign out',
    signOutFailed: "We couldn't sign you out. Try again.",
    help: 'Help & support',
    profileMenu: (name: string) => `Account menu for ${name}`,
    profileSettings: 'Profile & settings',
    roleAdmin: 'Admin',
    roleUser: 'User',
    roleIn: (role: string, workspace: string) => `${role} in ${workspace}`,
    openNavigation: 'Open navigation',
    closeNavigation: 'Close navigation',
    sidebarSheetTitle: 'Navigation',
    sidebarSheetDescription: 'Pages of your workspace.',
    settings: 'Settings',
    back: 'Back',
    breadcrumb: 'Breadcrumb',
    search: 'Search',
    searchShortcut: '⌘K',
    searchReason: 'Search is not available yet.',
    notifications: 'Notifications',
    notificationsReason: 'Notifications are not available yet.',
    createBlock: '+ Create block',
    pageContent: 'Page content',
    retry: 'Retry',
    loadFailed: (items: string) => `We couldn't load ${items}. Try again.`,
    loadingItems: (items: string) => `Loading ${items}`,
    // Workspace switcher (Story 1.17). The message after a role drop is `workspace-role` in the catalogue.
    switchWorkspace: 'Switch workspace',
    switchWorkspaceCard: (name: string) => `Switch workspace, current: ${name}`,
    workspaceList: 'Workspaces',
    searchWorkspaces: 'Search workspaces',
    noWorkspaceMatch: 'No workspace matches your search.',
    currentWorkspace: 'Current workspace',
    switchFailed: "We couldn't switch workspace. Try again.",
    switchSave: 'Save',
    // The 403 page (Story 1.19); its message is `perm-denied` in the catalogue.
    forbiddenTitle: 'Access denied',
    forbiddenBack: 'Back to your overview',
} as const;

// Labels of User configuration, the Workspace's users table (Story 1.20; UX-DR-37, 261, 263, 273, 279, 282).
// Its messages are the catalogue's `list-empty`, `list-no-match` and `perm-denied`; the rest is table vocabulary.
export const userListLabels = {
    caption:
        'Users with access to this workspace, with their role, status and when they were last active',
    toolbar: 'User list tools',
    search: 'Search users',
    searchPlaceholder: 'Search by name or email',
    clearSearch: 'Clear search',
    invite: 'Invite user',
    columns: {
        name: 'Name',
        email: 'Email',
        role: 'Role',
        status: 'Status',
        groups: 'Groups',
        last_active: 'Last active',
    },
    roles: { admin: 'Admin', user: 'User' },
    statuses: {
        active: 'Active',
        invited: 'Invited',
        deactivated: 'Deactivated',
    },
    noGroups: 'No groups',
    noName: 'No name yet',
    noLastActive: 'Not active yet',
    sorted: (column: string, descending: boolean) =>
        `Sorted by ${column}, ${descending ? 'descending' : 'ascending'}`,
    count: (matched: number, total: number) =>
        `${matched} of ${total} ${total === 1 ? 'user' : 'users'}`,
    tableRegion: 'Users table',
    emptyCell: '—',
    pagination: 'User list pages',
    previous: 'Previous',
    next: 'Next',
    pageNumber: (page: number) => `Page ${page}`,
    pageChanged: (page: number, shown: number) =>
        `Page ${page}, ${shown} ${shown === 1 ? 'user' : 'users'}`,
    actions: 'Actions',
    resend: 'Resend',
    revoke: 'Revoke',
    resendFor: (email: string) => `Resend the invitation to ${email}`,
    revokeFor: (email: string) => `Revoke the invitation to ${email}`,
    resent: (email: string) => `Invitation sent again to ${email}.`,
    revoked: (email: string) => `Invitation to ${email} revoked.`,
    invited: (email: string) => `Invitation sent to ${email}.`,
} as const;

// Labels of the inline invite form on User configuration (Story 1.21). Its messages are the catalogue's `field-error`,
// `saved`, `save-failed.form`, `throttled` and `perm-denied`; the rest is form vocabulary.
export const inviteLabels = {
    title: 'Invite user',
    region: 'Invite a user',
    email: 'Email',
    emailHelper: 'We send the invitation link to this address.',
    role: 'Role',
    roleMenuLabel: (role: string) => `Role: ${role}`,
    roles: { user: 'User', admin: 'Admin' },
    roleDescriptions: {
        user: 'Uses dashboards and blocks. No Admin access.',
        admin: 'Works in the Admin area with the permissions you choose.',
    },
    permissions: 'Admin permissions',
    permissionsHelper: 'You can only give permissions you hold yourself.',
    permissionsNone: 'You hold no permissions you can give.',
    permissionLabels: {
        'data_sources.manage': 'Manage data sources',
        'blocks.edit': 'Edit blocks',
        'blocks.publish': 'Publish blocks',
        'templates.manage': 'Manage templates',
        'users.manage': 'Manage users',
        'settings.manage': 'Manage system settings',
        'audit.view': 'View the audit log',
        'data.preview_as_user': 'Preview data as a user',
        'access.manage': 'Manage access',
    } as Record<string, string>,
    confirmPassword: 'Your password',
    confirmPasswordHelper:
        'Enter your password to give these permissions. We ask again each time.',
    confirmPasswordRequired: 'Enter your password to give these permissions.',
    confirmPasswordWrong: 'That password is not right. Try again.',
    send: 'Send invitation',
    cancel: 'Cancel',
    retry: 'Retry',
    resendPending: 'Resend invitation',
    emailPending:
        'This email already has a pending invitation. Resend it to send a new link; the earlier link stops working.',
    emailMember: 'This email already belongs to a member of this workspace.',
    notConfigured:
        "Invitations aren't set up yet. Ask a platform operator to set the invitation lifetime.",
    notHeld:
        "You can't give a permission you don't hold. Reload the page and try again.",
    throttled: 'Too many attempts. Wait a minute, then try again.',
    resendNotHeld:
        "You can't resend this invitation: it grants permissions you don't hold.",
    gone: 'This invitation is no longer pending. The list has been refreshed.',
    deliveryFailed:
        'The invitation is saved as Invited, but its email did not go out. Retry sends it again.',
} as const;

// Labels of the inline Roles & permissions editor on User configuration (Story 1.22). Its messages are the catalogue's
// `saved`, `save-failed.form`, `throttled` and `field-error`; the role vocabulary is `inviteLabels`'.
export const accessLabels = {
    edit: 'Edit access',
    editFor: (name: string) => `Edit access for ${name}`,
    closeFor: (name: string) => `Close the access editor for ${name}`,
    region: (name: string) => `Roles and permissions for ${name}`,
    title: (name: string) => `Roles & permissions for ${name}`,
    userNoPermissions:
        'A User holds no permissions. Choose Admin to give permissions.',
    notHeldReason:
        "You don't hold this permission, so you can't give or remove it.",
    selfReason: "You can't change your own role or permissions.",
    passwordHelper:
        'Enter your password to confirm this change. We ask again each time.',
    passwordRequired: 'Enter your password to confirm this change.',
    save: 'Save changes',
    close: 'Close',
    retry: 'Retry',
    inactiveReason:
        "This member is deactivated, so their access can't be changed.",
    savedFor: (name: string) => `Access updated for ${name}.`,
    downgradeTitle: (name: string) => `Change ${name} to User?`,
    downgradeImpact: (name: string) =>
        `${name} will lose Admin access and every permission. Their open Admin pages stop working on their next request.`,
    downgradeVerb: 'Change',
    downgradeObject: (name: string) => `${name} to User`,
    lastHolder:
        'This person is the last one who can manage users. Give Manage users to another Admin first.',
    conflict:
        'Someone else changed this member. The latest role and permissions are shown.',
    held: "You can't give or remove a permission you don't hold.",
    inactive: "This member is deactivated, so their access can't be changed.",
    gone: 'This member is no longer in the workspace. The list has been refreshed.',
    unchanged: 'Nothing to save: the role and permissions are as they were.',
} as const;

// Labels of Deactivate and Reactivate on a member row (Story 1.24). Its messages are the catalogue's `saved` and
// `throttled`; the rollback toast is a `rollback` toast (an alert, never auto-dismissed) with the wording here.
export const statusLabels = {
    deactivate: 'Deactivate',
    reactivate: 'Reactivate',
    deactivateFor: (name: string) => `Deactivate ${name}`,
    reactivateFor: (name: string) => `Reactivate ${name}`,
    dialogTitle: (name: string) => `Deactivate ${name}?`,
    dialogImpact: (name: string) =>
        `${name} will lose access to this workspace and their open sessions here end now. Their role, permissions and groups are kept, and you can reactivate them later.`,
    dialogVerb: 'Deactivate',
    deactivated: (name: string) => `${name} deactivated.`,
    reactivated: (name: string) => `${name} reactivated.`,
    rollback: (action: 'deactivate' | 'reactivate', name: string) =>
        `We couldn't ${action} ${name}. Their access is unchanged. Try again.`,
    lastHolder:
        'This person is the last one who can manage users. Give Manage users to another Admin first.',
    self: "You can't deactivate your own membership.",
    conflict:
        'Someone else changed this member. The latest state is shown. Try again.',
    forbidden: "You can't change this member.",
    gone: 'This member is no longer in the workspace. The list has been refreshed.',
} as const;

// Labels of the Groups view of User configuration (Story 1.23). Its messages are the catalogue's `list-empty`,
// `list-no-match`, `saved`, `save-failed.form`, `throttled` and `perm-denied`; the rest is vocabulary.
export const groupLabels = {
    title: 'Groups',
    pageTitle: 'User groups',
    items: 'groups',
    action: 'Create a group',
    back: 'Back to User configuration',
    openGroups: 'Groups',
    caption: 'Groups in this workspace, with their member counts',
    tableRegion: 'Groups table',
    toolbar: 'Group list tools',
    search: 'Search groups',
    searchPlaceholder: 'Search by group name',
    clearSearch: 'Clear search',
    create: 'Create group',
    createTitle: 'Create a group',
    createRegion: 'Create a group',
    name: 'Group name',
    nameHelper:
        'Up to 64 characters. Each group in the workspace needs its own name.',
    nameRequired: 'Enter a group name.',
    nameTooLong: 'Use 64 characters or fewer.',
    nameInvalid:
        "Use letters, numbers and punctuation only. Don't use control characters.",
    nameTaken: 'A group with this name already exists. Choose another name.',
    save: 'Save',
    cancel: 'Cancel',
    retry: 'Retry',
    close: 'Close',
    columns: { name: 'Name', members: 'Members', created: 'Created' },
    actions: 'Actions',
    manage: 'Manage',
    manageFor: (name: string) => `Manage the group ${name}`,
    closeFor: (name: string) => `Close the editor for ${name}`,
    editorRegion: (name: string) => `Group ${name}`,
    editorTitle: (name: string) => `Group ${name}`,
    sorted: (column: string, descending: boolean) =>
        `Sorted by ${column}, ${descending ? 'descending' : 'ascending'}`,
    count: (matched: number, total: number) =>
        `${matched} of ${total} ${total === 1 ? 'group' : 'groups'}`,
    memberCount: (count: number) =>
        `${count} ${count === 1 ? 'member' : 'members'}`,
    created: (name: string) => `Group ${name} created.`,
    renamed: (name: string) => `Group renamed to ${name}.`,
    deleted: (name: string) => `Group ${name} deleted.`,
    added: (member: string, group: string) => `${member} added to ${group}.`,
    removed: (member: string, group: string) =>
        `${member} removed from ${group}.`,
    rename: 'Rename group',
    renameSave: 'Save name',
    members: 'Members',
    noMembers: 'No members yet. Search below to add some.',
    membersOf: (name: string) => `Members of ${name}`,
    deactivated: 'Deactivated',
    deactivatedNote: 'Deactivated members keep their groups.',
    remove: 'Remove',
    removeFor: (member: string, group: string) =>
        `Remove ${member} from ${group}`,
    addMembers: 'Add members',
    memberSearch: 'Search members to add',
    memberSearchPlaceholder: 'Search by name or email',
    add: 'Add',
    addFor: (member: string, group: string) => `Add ${member} to ${group}`,
    searchResults: (count: number) =>
        `${count} ${count === 1 ? 'member' : 'members'} found`,
    searchNone: 'No members to add match this search.',
    searchHint: 'Type a name or an email to find members to add.',
    searchFailed: "We couldn't search members. Try again.",
    delete: 'Delete group',
    deleteTitle: (name: string) => `Delete ${name}?`,
    deleteImpact: (name: string, count: number) =>
        `${name} has ${count} ${count === 1 ? 'member' : 'members'}. Deleting the group removes ${count === 1 ? 'that membership' : 'those memberships'} only; the people stay in the workspace.`,
    deleteObject: (name: string) => `group ${name}`,
    gone: 'This group no longer exists. The list has been refreshed.',
    unchanged: 'Nothing to save: the name is as it was.',
    emptyCell: '—',
} as const;

// Labels of System settings and its Host allowlist (Story 2.1; UX-DR-262, 263). The messages for the standard
// states (`list-empty`, `msg:saved`, `msg:perm-denied`, the load failure) come from the catalogue and `shellLabels`.
export const settingsLabels = {
    pageTitle: 'System settings',
    pageSubtitle: 'Settings that apply to the whole workspace.',
    sections: 'Settings',
    hostAllowlist: 'Host allowlist',
    hostAllowlistSummary:
        'The hosts this workspace may call. Dashflow contacts only hosts you approve here.',
    userAttributes: 'User attributes',
    userAttributesSummary:
        "The attributes, such as a region or an employee number, that endpoints can use to return only each user's own data.",
} as const;

// Labels of System settings > User attributes and of the member Attributes editor (Story 2.12; UX-DR-115, 23, 263, 282).
// Its other messages are the catalogue's `saved`, `throttled`, `perm-denied` and `save-failed`.
export const userAttributeLabels = {
    pageTitle: 'User attributes',
    pageSubtitle:
        "Define the attributes that can be set for each member. Endpoints use them to return only a member's own data.",
    items: 'attributes',
    action: '+ Add attribute',
    back: 'Back to System settings',
    caption: 'User attributes with their key id, label and type',
    tableRegion: 'User attributes table',
    toolbar: 'User attributes tools',
    search: 'Search attributes',
    searchPlaceholder: 'Search by key id or label',
    clearSearch: 'Clear search',
    count: (matched: number, total: number) =>
        `${matched} of ${total} ${total === 1 ? 'attribute' : 'attributes'}`,
    columns: { key_id: 'Key id', label: 'Label', value_type: 'Type' },
    actions: 'Actions',
    types: {
        text: 'Text',
        identifier: 'Identifier',
        integer: 'Whole number',
    } as Record<string, string>,
    typeHelpers: {
        text: 'Up to 256 characters of visible text.',
        identifier: 'Up to 128 letters, digits, dots, hyphens or underscores.',
        integer: 'A whole number of up to 18 digits, such as 42 or -7.',
    } as Record<string, string>,
    // The inline add form.
    addTitle: 'Add an attribute',
    addRegion: 'Add an attribute',
    save: 'Add attribute',
    cancel: 'Cancel',
    keyId: 'Key id',
    keyIdHelper:
        'Lower case letters, digits and underscores, starting with a letter, up to 48 characters. It cannot be changed later.',
    label: 'Label',
    labelHelper: 'Up to 64 characters. You can rename it later.',
    type: 'Type',
    typeHelper: 'Chosen once: the type cannot be changed later.',
    added: (label: string) => `${label} added to the user attributes.`,
    // Field errors by the server's reason.
    reasons: {
        duplicate: 'This is already used. Choose another.',
    } as Record<string, string>,
    keyIdTaken: 'This key id is already used. Choose another.',
    labelTaken: 'This label is already used. Choose another.',
    keyIdInvalid:
        'Use lower case letters, digits and underscores, starting with a letter (up to 48 characters).',
    labelInvalid:
        'Enter a label of 1 to 64 characters, without control or invisible characters.',
    typeInvalid: 'Choose a type.',
    // Inline rename.
    rename: 'Rename',
    renameFor: (label: string) => `Rename ${label}`,
    renameField: (keyId: string) => `Label for ${keyId}`,
    renameSave: 'Save label',
    renameSaveFor: (label: string) => `Save the new label for ${label}`,
    renameCancel: 'Cancel',
    renamed: (label: string) => `Label changed to ${label}.`,
    conflict:
        'Someone else changed this attribute. The latest label is shown. Check it, then save again if you still want to.',
    gone: 'This attribute no longer exists. The list has been refreshed.',
    // Member Attributes editor (User configuration).
    attributes: 'Attributes',
    attributesFor: (name: string) => `Edit attributes for ${name}`,
    closeFor: (name: string) => `Close the attributes editor for ${name}`,
    memberRegion: (name: string) => `Attributes for ${name}`,
    memberTitle: (name: string) => `Attributes for ${name}`,
    memberSubtitle:
        'These values are stored encrypted. They are shown only here, to people who manage users.',
    memberNone:
        'No attributes are defined yet. Define them in System settings > User attributes.',
    memberNoneAction: 'Open User attributes',
    memberSave: 'Save attributes',
    memberClose: 'Close',
    memberLoading: 'Loading attributes',
    memberNotSet: 'Not set',
    memberHelper: (type: string) =>
        `${type}. A value cannot be cleared once set.`,
    memberSaved: (name: string) => `Attributes updated for ${name}.`,
    memberUnchanged: 'Nothing to save: no value was changed.',
    memberReasons: {
        empty: 'Enter a value.',
        too_long: 'This value is too long.',
        invalid: 'This value is not valid for this type.',
        undefined_key: 'This attribute is not defined any more.',
    } as Record<string, string>,
    memberUnavailable:
        'Attributes are unavailable right now because the keys that protect them are not set up. Nothing was saved. Ask an operator to check them.',
    memberSelf: "You can't change your own attributes.",
    memberGone:
        'This member is no longer in the workspace. The list has been refreshed.',
    retry: 'Retry',
} as const;

export const hostAllowlistLabels = {
    pageTitle: 'Host allowlist',
    pageSubtitle:
        'Dashflow only contacts hosts on this list. Adding a host does not contact it.',
    items: 'hosts',
    action: '+ Add host',
    back: 'Back to System settings',
    caption:
        'Hosts this workspace may call, with scheme, port and who added them',
    tableRegion: 'Host allowlist table',
    toolbar: 'Host allowlist tools',
    search: 'Search hosts',
    searchPlaceholder: 'Search by host',
    clearSearch: 'Clear search',
    count: (matched: number, total: number) =>
        `${matched} of ${total} ${total === 1 ? 'host' : 'hosts'}`,
    sorted: (column: string, descending: boolean) =>
        `Sorted by ${column}, ${descending ? 'descending' : 'ascending'}`,
    columns: {
        host: 'Host',
        scheme: 'Scheme',
        port: 'Port',
        added_by: 'Added by',
        added: 'Added on',
    },
    actions: 'Actions',
    notEncrypted: 'Not encrypted',
    notEncryptedNote: 'Calls to this host are sent over plain http.',
    unknownMember: 'Unknown member',
    // The inline add form.
    add: '+ Add host',
    addTitle: 'Add a host',
    addRegion: 'Add a host',
    save: 'Add host',
    cancel: 'Cancel',
    host: 'Host',
    hostHelper:
        'A host name or IP address, with a port if it is not the default, such as api.example.com:8443. No scheme, path or wildcard.',
    scheme: 'Scheme',
    schemeHttps: 'https',
    schemeHttp: 'http',
    schemeHelper:
        'https is encrypted. Plain http is allowed for now and is marked Not encrypted.',
    schemeNotice: 'Plain http is not encrypted.',
    // Field errors by the server's reason.
    reasons: {
        empty: 'Enter a host.',
        whitespace:
            'The host cannot contain spaces. Remove them and try again.',
        forbidden_character:
            'Enter a host name only: no scheme, path, query, user name, wildcard or percent sign.',
        non_ascii:
            'Use the ASCII (punycode) form of the host name, such as xn--bcher-kva.example.',
        malformed:
            'Enter a host, or a host and port such as api.example.com:8443. Put an IPv6 address in square brackets.',
        invalid_label:
            'Each part of a host name uses letters, digits and inner hyphens only.',
        too_long: 'The host name is too long.',
        numeric_address:
            'Write an IP address as four numbers separated by dots, or as an IPv6 address in square brackets.',
        blocked_address: 'This address is in a range that cannot be allowed.',
        invalid_port: 'The port must be a number from 1 to 65535.',
        invalid_scheme: 'Choose https or http.',
        duplicate: 'This host and port are already on the allowlist.',
    } as Record<string, string>,
    hostInvalid: 'This host is not valid. Check it and try again.',
    // Changes.
    added: (host: string) => `${host} added to the host allowlist.`,
    removed: (host: string) => `${host} removed from the host allowlist.`,
    // The list changed under the Admin (409): the page shows the fresh list and keeps what was typed.
    conflict:
        'The host allowlist was changed by someone else. The list below is up to date. Check your entry, then add it again.',
    conflictRemove:
        'The host allowlist was changed by someone else. The list is up to date. Remove the host again if you still want to.',
    gone: 'This host is no longer on the allowlist. The list has been refreshed.',
    // Removal (UX-DR-262: alertdialog, focus on Cancel, the destructive button repeats the host).
    remove: 'Remove',
    removeFor: (host: string) => `Remove ${host}`,
    removeTitle: (host: string) => `Remove ${host}?`,
    removeImpact: (host: string) =>
        `${host} will no longer be an approved host. Calls to it are refused from now on.`,
    removeDependents: (names: string[]) =>
        `${names.length === 1 ? 'This data source' : 'These data sources'} will be blocked on the next call: ${names.join(', ')}.`,
    removeNoDependents: 'No data sources use this host.',
    removeDependentsFailed:
        "We couldn't check which data sources use this host. They may be blocked on their next call.",
    removeObject: (host: string) => host,
    removeVerb: 'Remove',
} as const;

// Draft blocks (Story 3.3; UX-DR-261, 263, 271, 282): the list of Drafts under Admin > Draft blocks. Messages for the standard
// states (`list-empty`, `list-no-match`, `msg:perm-denied`, the failure row) come from the catalogue and `shellLabels`.
export const draftBlockLabels = {
    pageTitle: 'Draft blocks',
    pageSubtitle:
        'Blocks you have started and not published. Open one to carry on where you stopped.',
    items: 'draft blocks',
    // The phrase of `list-empty` ("No draft blocks yet. Create a block to start.").
    action: 'Create a block',
    create: '+ Create block',
    caption:
        'Draft blocks of this workspace, with category, type, version, owner and last update',
    tableRegion: 'Draft blocks table',
    toolbar: 'Draft block tools',
    search: 'Search draft blocks',
    searchPlaceholder: 'Search by name, type or category',
    clearSearch: 'Clear search',
    count: (matched: number, total: number) =>
        `${matched} of ${total} ${total === 1 ? 'draft block' : 'draft blocks'}`,
    columns: {
        name: 'Name',
        category: 'Category',
        type: 'Type',
        version: 'Version',
        owner: 'Owner',
        updated: 'Last updated',
        status: 'Status',
        actions: 'Actions',
    },
    // The status badge of every row on this list.
    draft: 'Draft',
    noCategory: '—',
    noOwner: '—',
    open: (name: string) => `Open ${name}`,
    // The Block Type keys the registry lists, by their display name; an unknown key shows as it is.
    types: { 'kpi-card': 'KPI card' } as Record<string, string>,
    // Discard.
    discard: 'Discard',
    discardAria: (name: string) => `Discard ${name}`,
    discardTitle: (name: string) => `Discard ${name}?`,
    discardImpact:
        'The draft and its sample data will be deleted. This cannot be undone. Published blocks are not affected.',
    discarded: (name: string) => `${name} discarded.`,
    gone: 'This draft no longer exists.',
};

// The Create block wizard (Story 3.4; UX-DR-86, 22, 23, 26, 274): the five-step shell, Step 1 (Configure Block) and the live preview. The
// messages `perm-publish` and `version-safe` come from the catalogue; everything else is here.
export const blockWizardLabels = {
    breadcrumb: 'Block management / Create block',
    pageTitle: 'Create block',
    untitled: 'Untitled block',
    draftBadge: '• Draft',
    saveDraft: 'Save draft',
    saving: 'Saving…',
    saved: 'Draft saved.',
    preview: 'Preview',
    publish: 'Publish block',
    continue: 'Continue',
    back: 'Back',
    loading: 'Loading the draft…',
    notFound: 'This draft no longer exists. It may have been discarded.',
    backToDrafts: 'Back to Draft blocks',
    loadFailed: "We couldn't load this draft.",
    retry: 'Retry',
    conflict:
        'This draft was changed somewhere else. Reload the page to see the latest version.',
    // After a take-over: the wizard shows the holder's last saved version.
    reloaded: 'Showing the latest saved version of this draft.',
    notReady: 'Finish the earlier steps first.',
    // Autosave and recovery (Story 3.5). `offline-editing`, `save-failed.draft`, `unsaved-changes` and `session-expired` come from the catalogue.
    retrySave: 'Retry',
    publishedTitle: 'This draft was published',
    // `publish-success` names the category; a block with none says it without.
    publishedNoCategory: (block: string, version: string) =>
        `${block} v${version} is live in the Block Library.`,
    publishedBody:
        'Someone published this draft, so it can no longer be edited here and nothing more will be saved.',
    publishedLink: 'Go to Published blocks',
    conflictTitle: 'This draft was changed somewhere else',
    conflictBody:
        'Choose which version to keep. Nothing is overwritten until you choose.',
    conflictMine: 'Your version',
    conflictTheirs: 'Saved version',
    conflictName: 'Name',
    conflictDescription: 'Description',
    conflictSize: 'Size',
    conflictSavedAt: 'Last saved',
    conflictNone: '(none)',
    conflictKeepMine: 'Keep my version',
    conflictUseTheirs: 'Use the saved version',
    conflictKept: 'Your version was saved.',
    conflictTook: 'The saved version is now open.',
    // Progress.
    progress: 'Create block progress',
    notStarted: '(not started)',
    edit: 'Edit',
    steps: [
        { key: 'configure', label: 'Configure Block' },
        { key: 'data-source', label: 'Data Source' },
        { key: 'map-data', label: 'Map Data' },
        { key: 'preview', label: 'Preview' },
        { key: 'publish', label: 'Save/Publish' },
    ],
    stepButton: (n: number, label: string, completed: boolean) =>
        `${n}. ${label}, ${completed ? 'completed' : 'not completed'}`,
    stepStatus: (n: number, total: number, label: string) =>
        `Step ${n} of ${total}: ${label}`,
    laterStep: 'This step is built in a later update.',
    // Step 1.
    sections: {
        basics: {
            title: 'Basic information',
            caption: 'Name the block and say what it is for.',
        },
        display: {
            title: 'Display & behavior',
            caption: 'How the block looks and what a user can do with it.',
        },
        period: {
            title: 'Period behavior',
            caption: 'Whether the block follows the dashboard date range.',
        },
    },
    name: 'Block name',
    namePlaceholder: 'Revenue today',
    nameRequired: 'Enter a name.',
    nameTooLong: (max: number) => `Use up to ${max} characters.`,
    description: 'Description',
    descriptionHelper: 'Shown to people browsing the Block Library.',
    descriptionRequired: 'Enter a description.',
    descriptionTooLong: (max: number) => `Use up to ${max} characters.`,
    category: 'Category',
    type: 'Block type',
    typeNames: { 'kpi-card': 'KPI card' } as Record<string, string>,
    icon: 'Icon',
    iconChange: 'Change',
    iconUseCategory: 'Use category icon',
    iconFromCategory: (name: string) => `From the ${name} category`,
    iconChosen: 'Chosen for this block',
    iconPopover: 'Choose an icon',
    iconSearch: 'Search icons',
    iconNone: 'No icon matches.',
    iconTile: (name: string) => name,
    width: 'Default width (columns)',
    widthHelper: (min: number, max: number) =>
        `Allowed: ${min} to ${max} columns.`,
    height: 'Default height',
    heights: {
        small: 'Small',
        medium: 'Medium (360 px)',
        large: 'Large',
    } as Record<string, string>,
    heightHelper: (min: string, max: string) => `Allowed: ${min} to ${max}.`,
    minWidth: 'Minimum width (columns)',
    maxWidth: 'Maximum width (columns)',
    minHeight: 'Minimum height',
    maxHeight: 'Maximum height',
    widthRange: (min: number, max: number) => `Use ${min} to ${max} columns.`,
    heightRange: (min: string, max: string) =>
        `Use a height from ${min} to ${max}.`,
    minAboveMax: 'The minimum cannot be more than the maximum.',
    outsideLimits: 'The default must be between the minimum and maximum.',
    showHeader: 'Show block header',
    showSubtitle: 'Show subtitle',
    allowResize: 'Allow resize',
    allowMinimize: 'Allow minimize',
    allowRefresh: 'Allow refresh',
    showFooterAction: 'Show footer action',
    showFooterActionHelper:
        'Adds an optional, empty Footer action slot to map later.',
    period: 'Period',
    periods: {
        follow_dashboard: 'Follow dashboard default',
        own_selector: 'Own selector',
        not_date_filtered: 'Not date-filtered',
    } as Record<string, string>,
    periodHelper:
        'Own selector is stored only for now: the block period selector arrives with dashboards.',
    errorSummary: 'Fix these fields',
    // Preview pane.
    previewTitle: 'Live preview',
    previewCaption: 'Changes appear here automatically.',
    devices: 'Preview device',
    deviceOptions: [
        { value: 'desktop', label: 'Desktop' },
        { value: 'tablet', label: 'Tablet' },
        { value: 'mobile', label: 'Mobile' },
    ],
    canvas: (device: string) => `${device} preview canvas`,
    sizePill: (columns: number, height: string) =>
        `${columns} ${columns === 1 ? 'column' : 'columns'} × ${height}`,
    summary: (columns: number, height: string, category: string) =>
        `${category} · ${columns} × ${height}`,
};

// Labels of the Data sources list and form (Story 2.3; UX-DR-207, 115, 261, 263, 23, 26, 22, 37, 39, 274, 282). The
// messages for the standard states (`list-empty`, `list-no-match`, `msg:saved`, `msg:perm-denied`, the allowlist miss
// `host-not-allowlisted`, the save failure) come from the catalogue and `shellLabels`.
export const dataSourceLabels = {
    pageTitle: 'Data sources',
    pageSubtitle:
        'The APIs this workspace reads. Dashflow only calls hosts on the host allowlist.',
    items: 'data sources',
    // The phrase of `list-empty` ("No data sources yet. Register a data source to start.").
    action: 'Register a data source',
    register: '+ Register data source',
    caption:
        'Data sources of this workspace, with host, authentication, health, last successful call and the blocks using them',
    tableRegion: 'Data sources table',
    toolbar: 'Data source tools',
    search: 'Search data sources',
    searchPlaceholder: 'Search by name or host',
    clearSearch: 'Clear search',
    count: (matched: number, total: number) =>
        `${matched} of ${total} ${total === 1 ? 'data source' : 'data sources'}`,
    sorted: (column: string, descending: boolean) =>
        `Sorted by ${column}, ${descending ? 'descending' : 'ascending'}`,
    columns: {
        name: 'Name',
        host: 'Host',
        auth_type: 'Auth type',
        health: 'Health',
        last_success: 'Last successful call',
        blocks: 'Blocks using it',
    },
    authTypes: {
        none: 'None',
        api_key: 'API key',
        bearer: 'Bearer token',
        basic: 'Basic',
        oauth2_client_credentials: 'OAuth2 client credentials',
    } as Record<string, string>,
    // Health (Story 2.18): the word beside every dot, and the last success.
    checking: 'Checking…',
    health: {
        checking: 'Checking…',
        healthy: 'Healthy',
        degraded: 'Degraded',
        unreachable: 'Unreachable',
    } as Record<string, string>,
    lastSuccess: (time: string) => `Last success ${time}`,
    noCall: '—',
    noCallYet: 'No successful call yet',
    // The health path field of the form: the path a probe adds to the Base URL.
    healthPath: 'Health path (optional)',
    healthPathHelper:
        'A path such as /health that Dashflow requests to check this data source when you save it, and later while it has no endpoints to fetch. Leave blank to use the Base URL. No query string, fragment or placeholders.',
    notEncrypted: hostAllowlistLabels.notEncrypted,
    notEncryptedNote: 'Calls to this data source are sent over plain http.',
    edit: (name: string) => `Edit ${name}`,
    saved: (name: string) => `${name} registered.`,
    gone: 'This data source no longer exists.',
    // The form.
    registerTitle: 'Register data source',
    editTitle: 'Edit data source',
    registerSubtitle:
        'Describe the API. Saving does not contact it, and the host must be on the host allowlist.',
    editSubtitle: (name: string) => `Changes to ${name} apply to later calls.`,
    back: 'Back to Data sources',
    formRegion: 'Data source',
    connection: 'Connection',
    name: 'Name',
    nameHelper:
        'Up to 64 characters. Each data source in the workspace needs its own name.',
    baseUrl: 'Base URL',
    baseUrlHelper:
        'Where the API lives, such as https://api.example.com/v1. No user name, query string or fragment.',
    headers: 'Default headers',
    headersHelper:
        'Sent with every call to this data source. Credentials are not headers here: they are added separately and never shown again.',
    headersNone: 'No default headers.',
    addHeader: '+ Add header',
    headerName: (n: number) => `Header ${n} name`,
    headerValue: (n: number) => `Header ${n} value`,
    removeHeaderButton: 'Remove',
    removeHeader: (n: number) => `Remove header ${n}`,
    headerRemoved: (n: number) => `Header ${n} removed.`,
    limits: 'Limits',
    limitsHelper: 'Leave a limit blank to use the platform setting.',
    timeout: 'Timeout (seconds)',
    maxResponse: 'Maximum response size (bytes)',
    maxPages: 'Maximum pages',
    ceiling: (value: number) => `The platform limit is ${value}.`,
    // Retention (Story 2.16): how much raw history the Data Source keeps.
    retention: 'Retention',
    retentionHelper:
        'Choose how much raw history to keep. Older data is deleted by the next sweep, which runs every few minutes. The current data is never deleted.',
    retentionModeLabel: 'Raw history',
    retentionModes: {
        latest: 'Latest only',
        window: 'Keep a window',
    } as Record<string, string>,
    retentionLatestHelper:
        'Keeps the current data. Superseded data is deleted after the grace period set for this deployment.',
    retentionWindowUnavailable:
        'Not available: no maximum window is set for this deployment.',
    retentionDays: 'Days to keep',
    retentionDaysHelper: (max: number | null) =>
        max === null
            ? 'A whole number of days, 1 or more.'
            : `A whole number of days, from 1 to ${max}.`,
    // Pagination (Story 2.11): how the API pages its answers. The Pagination section sits in the Limits area.
    pagination: 'Pagination',
    paginationHelper:
        'Choose how this API splits its answers into pages. Dashflow follows the pages up to the limits above and merges them into one response. Nothing is saved from a test.',
    paginationStyle: 'Pagination style',
    paginationStyles: {
        none: 'None (one request)',
        page: 'Page number',
        offset: 'Offset',
        cursor: 'Cursor',
        link_header: 'Link header',
    } as Record<string, string>,
    paginationStyleHelper: {
        none: 'The API returns everything in one answer.',
        page: 'Sends the page number, starting at 1, until a page has no records.',
        offset: 'Sends the offset, starting at 0 and growing by the records received, until a page has no records.',
        cursor: 'Sends the cursor read from each answer, as a plain value, until an answer has none.',
        link_header:
            'Follows the next link in the Link header, only on the same address as the request.',
    } as Record<string, string>,
    paginationParam: (style: string) =>
        style === 'cursor'
            ? 'Cursor parameter'
            : style === 'offset'
              ? 'Offset parameter'
              : 'Page parameter',
    paginationParamHelper: 'The query parameter that carries it, such as page.',
    paginationSizeParam: 'Page size parameter (optional)',
    paginationSizeParamHelper:
        'The query parameter that carries the page size, such as limit. Leave blank to send no size.',
    paginationSize: 'Page size',
    paginationSizeHelper: 'How many records to ask for in each page.',
    paginationRecordsPath: 'Records path (optional)',
    paginationRecordsPathHelper:
        'Where the records array sits in each page, such as data.items. Leave blank when the answer itself is the array.',
    paginationCursorPath: 'Cursor path',
    paginationCursorPathHelper:
        'Where the next cursor sits in each answer, such as meta.next. No cursor ends the run.',
    refresh: 'Refresh',
    live: 'Supports Live refresh (~30 s)',
    liveHelper:
        'Only turn this on if the API can handle a call every 30 seconds per block.',
    save: 'Save',
    create: 'Register data source',
    cancel: 'Cancel',
    saving: 'Saving…',
    loading: 'Loading the data source',
    loadFailed: "We couldn't load these settings. Try again.",
    notFound: 'This data source no longer exists.',
    // Save is unavailable until the Base URL's host is allowed (`aria-disabled`, reason adjacent: UX-DR-22).
    saveBlocked:
        "Save is unavailable until the base URL's host is on the workspace allowlist.",
    saveBlockedUrl: 'Save is unavailable until the base URL is accepted.',
    saveBlockedToken:
        "Save is unavailable until the token URL's host is on the workspace allowlist.",
    saveBlockedTokenUrl: 'Save is unavailable until the token URL is accepted.',
    tokenUrlRequired: 'Enter the token URL.',
    clientIdRequired: 'Enter the client ID.',
    nameRequired: 'Enter a name.',
    baseUrlRequired: 'Enter the base URL.',
    // The same refusals for the OAuth2 token URL (Story 2.7), by the server's reason.
    tokenUrlReasons: {
        empty: 'Enter the token URL.',
        'too-long': 'The token URL is too long.',
        whitespace:
            'The token URL cannot contain spaces. Remove them and try again.',
        malformed:
            'Enter a full address such as https://auth.example.com/oauth/token.',
        scheme: 'The token URL must start with http:// or https://.',
        userinfo: 'Leave the user name and password out of the token URL.',
        query: 'Leave the query string out of the token URL.',
        fragment: 'Leave the fragment (#) out of the token URL.',
        'invalid-host':
            'The host is not valid. Use a host name, or an IP address that is not in a private or reserved range.',
        'https-required':
            'This workspace requires https. Change the token URL to start with https://.',
    } as Record<string, string>,
    // Field errors by the server's reason; a reason without an entry shows the server's own message.
    reasons: {
        'name-required': 'Enter a name.',
        'name-too-long': 'The name can have at most 64 characters.',
        'name-invalid-characters':
            'The name contains characters that are not allowed. Remove control or invisible characters.',
        'name-taken':
            'A data source with this name already exists. Choose another name.',
        empty: 'Enter the base URL.',
        'too-long': 'The base URL is too long.',
        whitespace:
            'The base URL cannot contain spaces. Remove them and try again.',
        malformed: 'Enter a full address such as https://api.example.com/v1.',
        scheme: 'The base URL must start with http:// or https://.',
        userinfo: 'Leave the user name and password out of the base URL.',
        query: 'Leave the query string out of the base URL.',
        fragment: 'Leave the fragment (#) out of the base URL.',
        'invalid-host':
            'The host is not valid. Use a host name, or an IP address that is not in a private or reserved range.',
        'https-required':
            'This workspace requires https. Change the base URL to start with https://.',
        'header-name-invalid':
            "A header name uses letters, digits and the characters ! # $ % & ' * + - . ^ _ ` | ~ only.",
        'header-name-reserved':
            'This header cannot be set as a default header. Credentials are added separately.',
        'header-name-duplicate': 'This header is already listed.',
        'header-value-invalid':
            'A header value uses visible ASCII characters only, on one line. Remove line breaks and other special characters.',
        'header-value-too-long': 'This header value is too long.',
        'too-many-headers': 'There are too many default headers.',
        'not-positive-integer':
            'Enter a whole number greater than zero, or leave it blank to use the platform setting.',
        'above-ceiling':
            'This is above the platform limit. Enter a smaller number.',
        'invalid-boolean': 'Choose on or off.',
        'headers-invalid':
            'The default headers are not valid. Reload the page and try again.',
        'auth-type-invalid': 'Choose an authentication type.',
        'api-key-name-required': 'Enter the name the API key is sent under.',
        'api-key-name-invalid':
            "The name uses letters, digits and the characters ! # $ % & ' * + - . ^ _ ` | ~ only.",
        'api-key-name-reserved':
            'This name is reserved. Choose another name for the API key.',
        'api-key-name-duplicate': 'A default header already uses this name.',
        'api-key-placement-invalid': 'Choose header or query string.',
        'health-path-too-long':
            'The health path can have at most 255 characters.',
        'health-path-placeholder':
            'The health path cannot hold {placeholders}: enter a fixed path such as /health.',
        'health-path-required': 'Enter the health path, starting with /.',
        'health-path-leading-slash': 'The health path must start with /.',
        'health-path-query': 'Leave the query string out of the health path.',
        'health-path-fragment':
            'Leave the fragment (#) out of the health path.',
        'retention-mode-invalid': 'Choose how much raw history to keep.',
        'retention-days-invalid': 'Enter a whole number of days, 1 or more.',
        'retention-days-above-maximum':
            'This is above the maximum window. Enter a smaller number.',
        'retention-days-not-allowed':
            'A number of days applies only to a window of history.',
        'retention-window-unavailable':
            'Keeping a window of history is not available: no maximum is set for this deployment.',
        'pagination-style-invalid': 'Choose how this API pages its answers.',
        'pagination-param-required': 'Enter the query parameter name.',
        'pagination-param-invalid':
            'A query parameter name uses letters, digits and the characters _ . ~ - only, up to 64 characters.',
        'pagination-size-param-required':
            'Enter the query parameter that carries the page size, or clear the page size.',
        'pagination-size-invalid':
            'Enter a whole number greater than zero for the page size, or clear the page size parameter.',
        'pagination-size-param-duplicate':
            'The page size needs a different query parameter from the page, offset or cursor.',
        'pagination-path-required':
            'Enter where the next cursor sits in the response, such as meta.next.',
        'pagination-path-invalid':
            'A path is keys and array positions separated by dots, such as data.items: letters, digits, _ and - only, at most 8 parts.',
        'secret-required': 'Enter a new value.',
        'secret-values-refused':
            'This form cannot carry a secret value. Remove it and try again.',
        'secret-value-invalid':
            'A credential uses visible ASCII characters only, on one line. Remove line breaks and other special characters.',
        'secret-value-too-long': 'This credential is too long.',
        'secret-slot-unused':
            'This credential does not belong to the chosen authentication type.',
        'secrets-invalid':
            'The credentials are not valid. Reload the page and try again.',
        'oauth-client-id-required': 'Enter the client ID.',
        'oauth-client-id-invalid':
            'The client ID uses visible ASCII characters only, without spaces.',
        'oauth-scope-invalid':
            'The scope is names separated by single spaces, using visible ASCII characters only.',
    } as Record<string, string>,
    // Someone else saved first (409): the typed values stay.
    conflict:
        'This data source was changed by someone else. Your changes are still here. Review them, then save again to keep them, or reload to see the latest.',
    reload: 'Reload latest',
    reloaded: 'Showing the latest saved values.',
    // Authentication (Story 2.4; UX-DR-29, 207, 23, 248). Secrets are write-only: a saved one shows only its date.
    authentication: 'Authentication',
    authenticationHelper:
        'Credentials are stored sealed and never shown again. Leave a saved credential alone to keep it.',
    authType: 'Authentication type',
    authOptions: {
        none: 'None',
        api_key: 'API key',
        bearer: 'Bearer token',
        basic: 'Basic (user name and password)',
        oauth2_client_credentials: 'OAuth2 client credentials',
    } as Record<string, string>,
    apiKeyName: 'API key name',
    apiKeyNameHelper:
        'The header or query parameter the key is sent under, such as X-Api-Key.',
    apiKeyPlacement: 'Send the key in',
    placementHeader: 'A header (recommended)',
    placementQuery: 'The query string',
    queryWarning:
        "A key in the query string can end up in the API's logs and in proxies. Use a header if the API allows it.",
    apiKey: 'API key',
    bearerToken: 'Token',
    basicUsername: 'User name',
    basicPassword: 'Password',
    // OAuth2 client credentials (Story 2.7): the client secret is write-only like every other credential.
    oauthTokenUrl: 'Token URL',
    oauthTokenUrlHelper:
        'Where Dashflow asks for an access token, such as https://auth.example.com/oauth/token. It must be on your workspace allowlist.',
    oauthClientId: 'Client ID',
    oauthClientSecret: 'Client secret',
    oauthScope: 'Scope (optional)',
    oauthScopeHelper: 'Names separated by spaces. Leave blank to ask for none.',
    secretHeader: 'Secret',
    secretHeaderHint:
        'Mark a header as secret to store its value sealed. It is then shown as set, never in full.',
    secretHeaderValue: (n: number) => `Header ${n} value (secret)`,
    confirmPassword: 'Your password',
    confirmPasswordHelper:
        'Confirm your password to change credentials or the authentication type.',
    confirmPasswordWrong: 'The password is incorrect.',
    credentialsUnavailable:
        'Credentials cannot be saved until the platform key is configured. Ask an operator to set it, then try again.',
    // Test connection (Story 2.5; UX-DR-135, 207, 22, 70, 276). The result text itself is the catalogue's `test-ok`,
    // `fetch-failed`, `host-not-allowlisted` and `blocked-address`.
    testConnection: 'Test connection',
    testing: 'Testing…',
    testRunning: 'A test is already running. Wait for its result.',
    testThrottled: (seconds: number) =>
        `Too many tests. Try again in ${seconds} ${seconds === 1 ? 'second' : 'seconds'}.`,
    testFailedTitle: 'Connection test failed',
    testSourceFallback: 'this data source',
    testHint:
        'Calls the base URL once with these headers and credentials. Nothing is saved.',
    // Story 2.6: the card also shows the catalogue's `not-json` and `response-too-large`.
    // Story 2.7: the catalogue is closed to the canonical rows of EXPERIENCE.md, so this message lives here.
    authFailed: (source: string) =>
        `Authentication failed. ${source} did not accept these credentials. Check the client ID, the client secret and the scope, then test again.`,
    // Story 2.11: the catalogue is closed too, so the page-limit message lives here. `pages` is the cap that was reached.
    tooManyPages: (pages: number) =>
        `Too many pages: this endpoint needs more than ${pages} pages, the page limit. Nothing was shown, so no totals are wrong. Narrow the request with parameters, or raise the page limit on the data source.`,
    // The page a paged call stopped at, in the card's title.
    failedPage: (page: number) => `Page ${page}`,
} as const;

// The Endpoints tab of a Data source (Story 2.9): the list, and the add and edit form with its `method-prefix`,
// `parameters-table`, header rows, body template and the POST read-only confirmation. The catalogue is pinned, so the
// copy that is not one of its canonical messages lives here (`post-readonly`, `list-empty`, `list-no-match`, `saved` and
// `perm-denied` come from the catalogue).
export const endpointLabels = {
    tabsLabel: 'Data source sections',
    tabSettings: 'Settings',
    tabEndpoints: 'Endpoints',
    pageTitle: 'Endpoints',
    pageSubtitle: (name: string) =>
        `The requests Blocks can select from ${name}. Saving does not send any request.`,
    sourceFallback: 'this data source',
    items: 'endpoints',
    // The phrase of `list-empty` ("No endpoints yet. Add an endpoint to start.").
    action: 'Add an endpoint',
    add: '+ Add endpoint',
    caption:
        'Endpoints of this data source, with method, path, revision and last update',
    tableRegion: 'Endpoints table',
    toolbar: 'Endpoint tools',
    search: 'Search endpoints',
    searchPlaceholder: 'Search by path or method',
    clearSearch: 'Clear search',
    count: (matched: number, total: number) =>
        `${matched} of ${total} ${total === 1 ? 'endpoint' : 'endpoints'}`,
    columns: {
        method: 'Method',
        path: 'Path',
        revision: 'Revision',
        updated: 'Updated',
    },
    edit: (method: string, path: string) => `Edit ${method} ${path}`,
    revisionValue: (n: number) => `r${n}`,
    back: 'Back to Data sources',
    missing: 'This data source no longer exists.',
    // Test endpoint (Story 2.10; UX-DR-135, 25, 26, 70, 276, 279, 282). The failure messages are the catalogue's (`fetch-failed`,
    // `host-not-allowlisted`, `blocked-address`, `not-json`, `response-too-large`) and the auth-failed label; the success line
    // is built here because the catalogue's `fetch-ok` reports a record count and a path that exist only after Mapping.
    testEndpoint: 'Test endpoint',
    testAction: (method: string, path: string) => `Test ${method} ${path}`,
    testActions: 'Actions',
    testTitle: 'Test endpoint',
    testSubtitle:
        'Sends this request once, with the values below, to the data source. The response is kept for a few minutes, only for you, and is never saved.',
    testValues: 'Test values',
    testValuesHelper:
        'Each parameter needs a value. A fixed parameter starts with its saved value; a date or period parameter takes a date written YYYY-MM-DD.',
    testValuesNone: 'This endpoint has no parameters. It is sent as it is.',
    testFieldHeader: (name: string) => `${name} header`,
    testFieldHint: (text: string) =>
        `A date, written YYYY-MM-DD. Filled in as ${text} when fetched.`,
    testRun: 'Run test',
    testRetry: 'Run the test again',
    testRunning: 'A test is already running. Wait for its result.',
    testMissing: (name: string) => `Enter a value for ${name} to run the test.`,
    testThrottled: (seconds: number) =>
        `Too many tests. Try again in ${seconds} ${seconds === 1 ? 'second' : 'seconds'}.`,
    testing: 'Testing…',
    testBack: 'Back to endpoints',
    testFailedTitle: 'Endpoint test failed',
    testOk: (status: number, ms: number) => `✓ ${status} · ${ms} ms`,
    testGone: 'This endpoint no longer exists. Go back to the list.',
    // The Endpoint changed while the test ran (or before it started): the result is for a revision that is no longer current.
    testStale: (current: number | null) =>
        current === null
            ? 'This result is not current. The endpoint was changed while the test ran.'
            : `This result is not current. The endpoint was changed to revision r${current} while the test ran.`,
    testStaleRetry: 'Retry',
    sampleExpired:
        'The sample is no longer available. Run the test again to see it.',
    sampleHeading: 'Sample response',
    sampleRegion: 'Sample response body',
    sampleStatus: (status: number, ms: number) => `Status ${status}, ${ms} ms`,
    sampleCopy: 'Copy sample',
    sampleCopied: 'Copied.',
    sampleCopyFailed: "Couldn't copy. Select the text and copy it by hand.",
    // The form.
    addTitle: 'Add endpoint',
    editTitle: 'Edit endpoint',
    formRegion: 'Endpoint',
    request: 'Request',
    endpoint: 'Endpoint',
    endpointHelper:
        'The method and the path, such as /api/v2/finance/revenue. The path is added to the data source base URL, and {name} marks a value that is filled in from a parameter.',
    methodLabel: 'Method',
    methodOptions: [
        { value: 'GET', label: 'GET' },
        { value: 'POST', label: 'POST' },
    ] as { value: string; label: string }[],
    pathLabel: 'Path',
    pathPlaceholder: '/api/v2/finance/revenue',
    parameters: 'Parameters',
    parametersHelper:
        'Each {name} in the path needs a parameter. A parameter that is not in the path is sent in the query string, or filled into the body template.',
    parametersNone: 'No parameters.',
    addParameter: '+ Add parameter',
    headers: 'Headers',
    headersHelper:
        'Sent with this request, after the data source default headers. Credentials are added to the data source, not here.',
    headersNone: 'No headers.',
    addHeader: '+ Add header',
    nameColumn: 'Name',
    bindingColumn: 'Binding',
    valueColumn: 'Value',
    removeColumn: 'Remove',
    paramName: (n: number) => `Parameter ${n} name`,
    paramBinding: (n: number) => `Parameter ${n} binding`,
    paramValue: (n: number) => `Parameter ${n} value`,
    removeParam: (n: number) => `Remove parameter ${n}`,
    paramRemoved: (n: number) => `Parameter ${n} removed.`,
    headerName: (n: number) => `Header ${n} name`,
    headerBinding: (n: number) => `Header ${n} binding`,
    headerValue: (n: number) => `Header ${n} value`,
    removeHeaderRow: (n: number) => `Remove header ${n}`,
    headerRemoved: (n: number) => `Header ${n} removed.`,
    removeRow: 'Remove',
    bindings: {
        fixed: 'Fixed value',
        date_range_from: 'Date range: from',
        date_range_to: 'Date range: to',
        period_start: 'Block period: start',
        period_end: 'Block period: end',
        user_id: 'User ID',
        user_email: 'User email',
        user_group: 'User group',
        user_attribute: 'User attribute',
    } as Record<string, string>,
    // The Binding select (Story 2.13): the user-context group, the attribute options (by label) and the tag of a bound row.
    userContextGroup: 'User context',
    attributeOption: (label: string) => `Attribute: ${label}`,
    attributeUnknown: (keyId: string) => `Attribute: ${keyId}`,
    userContextTag: 'user context',
    // The bound row's value text: never a value. Read out as one phrase.
    userContextResolved: 'user context',
    sharedData: 'shared data',
    sharedDataHint:
        'Everyone with access to a block that uses this endpoint sees the same data.',
    userContextHint:
        "Filled in from the signed-in user's own data. Users can't change it.",
    dataColumn: 'Data',
    // The scheduled fetch of an Endpoint (Story 2.14): the time of the last good response, or why there is none.
    syncColumn: 'Last success',
    lastSuccess: (time: string) => `Last success ${time}`,
    noSuccessYet: 'No successful call yet',
    lastChecked: (time: string) => `Checked ${time}`,
    dataAsOf: (time: string) => `Data as of ${time}`,
    notScheduled: 'Not scheduled',
    // The reason a fetch is not scheduled, by the server's reason; the date-bound rows without a test value are named.
    notScheduledReasons: {
        user_context:
            'Data that depends on the user is fetched when someone subscribes to it, not on a schedule.',
        test_values: (names: string[]) =>
            `Add a test date for ${names.join(', ')} to schedule it.`,
        no_interval: 'No refresh interval has been set.',
    },
    testValuesTitle: 'Test dates for the schedule',
    testValuesFormHelper:
        'The scheduled fetch needs one date for each date range or period row. Each is written YYYY-MM-DD. A fixed row keeps its saved value.',
    testValueLabel: (name: string) => `Test date for ${name}`,
    testValueHeaderName: (name: string) => `${name} header`,
    scopeByCaller: "Keep each user's data separate",
    scopeByCallerHelper:
        'Fetches are kept per user instead of shared. Available when a parameter or header uses user context.',
    // Fetch as user (Story 2.13). The failure messages are the catalogue's, plus `access.context_missing` (a label here).
    fetchAsUser: 'Fetch as user',
    fetchAsUserAction: (method: string, path: string) =>
        `Fetch ${method} ${path} as a user`,
    fetchAsUserTitle: 'Fetch as user',
    fetchAsUserSubtitle:
        'Sends this request once as the member you choose, using their own ID, email, group and attributes. The response is kept for a few minutes, only for you, and is never saved. The member is notified.',
    fetchAsUserMember: 'Member',
    fetchAsUserMemberSearch: 'Search members',
    fetchAsUserMemberChoose: 'Choose a member',
    fetchAsUserMemberNone: 'No active members match.',
    fetchAsUserMemberRequired: 'Choose a member to fetch as.',
    fetchAsUserRun: 'Fetch as user',
    fetchAsUserFailedTitle: 'Fetch as user failed',
    fetchAsUserNoPermission: "You don't have permission to fetch as a user.",
    contextMemberUnavailable:
        'This member is no longer active, so nothing was sent.',
    contextMissing: (keys: string[]) =>
        keys.length === 0
            ? "This member's data can't be sent with this request, so nothing was sent."
            : `This member has no value for ${keys.join(', ')}, so nothing was sent.`,
    userBoundNotice:
        'This endpoint uses user context. Use Fetch as user to see what a member would get.',
    boundPrefix: 'Filled in when fetched:',
    boundHint: (text: string) => `Filled in when fetched: ${text}`,
    body: 'Body template',
    bodyHelper:
        'JSON sent as the request body. Write a parameter as {"$param": "name"}, as a whole value. It cannot be written inside text or used as a key.',
    bodyPlaceholder: '{ "from": { "$param": "from" } }',
    postReadonlyBlocked:
        'Save is unavailable until you confirm that this POST only reads data.',
    riskTitle: 'Confirm a read-only POST',
    riskDescription:
        'Dashflow will send this POST to the data source whenever a block needs its data. Confirm only if the endpoint reads and never changes anything.',
    riskConfirm: 'Confirm read-only',
    save: 'Save',
    saving: 'Saving…',
    cancel: 'Cancel',
    loadFailed: "We couldn't load this endpoint. Try again.",
    endpointGone: 'This endpoint no longer exists. Go back to the list.',
    discardTitle: 'Discard your changes?',
    discardVerb: 'Discard',
    discardObject: 'changes',
    saveFailed: "We couldn't save this endpoint. Try again.",
    throttled: 'Too many saves. Wait a minute, then try again.',
    // Someone else saved first (409): the typed values stay.
    conflict:
        'This endpoint was changed by someone else. Your changes are still here. Review them, then save again to keep them, or reload to see the latest.',
    reload: 'Reload latest',
    reloaded: 'Showing the latest saved values.',
    // Field errors by the server's reason; a reason without an entry shows the server's own message.
    reasons: {
        'method-not-allowed':
            'Only GET and POST are allowed. Dashflow never sends a request that changes data.',
        'path-required': 'Enter the path, starting with /.',
        'path-too-long': 'The path is too long.',
        'path-absolute':
            'Enter a path such as /api/v2/revenue, not a full address. It is added to the data source base URL.',
        'path-protocol-relative':
            'Enter a path such as /api/v2/revenue, not a host. It is added to the data source base URL.',
        'path-leading-slash': 'The path must start with /.',
        'path-invalid-characters':
            'The path cannot contain spaces, control characters or characters outside ASCII. Percent-encode them.',
        'path-backslash': 'The path cannot contain a backslash.',
        'path-query':
            'Leave the query string out of the path. Add query values as parameters.',
        'path-fragment': 'Leave the fragment (#) out of the path.',
        'path-empty-segment':
            'The path cannot have an empty segment (// or a trailing /).',
        'path-dot-segment': 'The path cannot have a . or .. segment.',
        'path-encoded-separator':
            'The path cannot contain an encoded separator (%2f or %5c).',
        'path-too-many-segments': 'The path has too many segments.',
        'path-placeholder-invalid':
            'A placeholder is a whole segment written {name}, starting with a letter and using letters, digits and _ only.',
        'path-placeholder-duplicate': 'A placeholder is used more than once.',
        'path-param-missing':
            'Every {name} in the path needs a parameter. Add the missing one.',
        'params-invalid': 'The parameters are not valid. Reload the page.',
        'too-many-params': 'There are too many parameters.',
        'param-name-invalid':
            'A parameter name uses letters, digits and the characters _ . ~ - only.',
        'param-name-duplicate': 'This parameter is already listed.',
        'param-value-required': 'Enter a value.',
        'param-value-invalid':
            "A path value cannot be empty, '.', '..' or contain '/'. Other values cannot hold control characters.",
        'param-value-too-long': 'This value is too long.',
        'binding-invalid': 'Choose a binding.',
        'binding-attribute-unknown':
            'Choose one of the attributes defined for this workspace.',
        'scope-requires-user-context':
            'Keeping data per user needs a parameter or header bound to user context.',
        'scope-invalid': 'The setting is not valid.',
        'headers-invalid': 'The headers are not valid. Reload the page.',
        'too-many-headers': 'There are too many headers.',
        'header-name-invalid':
            "A header name uses letters, digits and the characters ! # $ % & ' * + - . ^ _ ` | ~ only.",
        'header-name-reserved':
            'This header cannot be set on an endpoint. Credentials are added to the data source.',
        'header-name-duplicate': 'This header is already listed.',
        'header-value-invalid':
            'A header value uses visible ASCII characters only, on one line. Remove line breaks and other special characters.',
        'body-template-invalid': "The body template isn't valid JSON.",
        'body-template-too-long': 'The body template is too long.',
        'body-template-too-deep': 'The body template is nested too deeply.',
        'body-template-not-allowed': 'Only a POST endpoint has a body.',
        'body-template-param-invalid':
            'A parameter is written {"$param": "name"}, alone in its object.',
        'body-template-param-unknown':
            'The body template uses a parameter that is not declared. Add it to the parameters.',
        'body-template-interpolation':
            'A parameter replaces a whole value only. It cannot be written inside text or used as a key.',
        'post-readonly-required': 'A POST must be marked as a read-only query.',
        'post-confirmation-required': 'Confirm that this POST only reads data.',
    } as Record<string, string>,
} as const;

// The soft lock on the Data source form (Story 2.8). The banner (`draft-locked`) and the notice (`draft-taken-over`) come from
// the catalogue, which is pinned; what it does not cover lives here, among it the notice for a holder whose changes were
// not saved before the take-over.
export const editLockLabels = {
    takeOver: 'Take over editing',
    close: 'Close',
    waiting: (name: string) =>
        `Waiting for ${name} to save their changes. This form becomes editable as soon as they have.`,
    takingOver: 'Taking over…',
    // The notice for a holder that did not confirm its flush: no claim that anything was saved.
    takenOverUnsaved: (name: string, time: string) =>
        `${name} took over editing at ${time}. Changes you had not saved were not kept.`,
    lost: 'You no longer hold the edit lock on this data source, so nothing was saved. Reload the page to edit again.',
    takeOverFailed: "We couldn't take over editing. Try again.",
    readOnly: 'This form is read-only while it is locked.',
    someone: 'Another admin',
    unavailable:
        "We couldn't check whether someone else is editing this data source, so it is read-only for now.",
    retry: 'Try again',
} as const;

// The wording the soft lock's banner and composable need, so a resource can word it its own way.
export type EditLockLabelSet = {
    takeOver: string;
    close: string;
    waiting: (name: string) => string;
    takenOverUnsaved: (name: string, time: string) => string;
    lost: string;
    takeOverFailed: string;
    readOnly: string;
    someone: string;
    unavailable: string;
    retry: string;
};

// The same banner on a Draft block (Story 3.6): draft-specific wording for what a Data source's says about "this form". `draft-locked` and
// `draft-taken-over` come from the catalogue.
export const draftLockLabels: EditLockLabelSet = {
    ...editLockLabels,
    waiting: (name: string) =>
        `Waiting for ${name} to save their changes. This draft becomes editable as soon as they have.`,
    lost: 'You no longer hold the edit lock on this draft, so your latest changes were not saved. Reload the page to edit again.',
    readOnly: 'This draft is read-only while someone else is editing it.',
    unavailable:
        "We couldn't check whether someone else is editing this draft, so it is read-only for now.",
};

// Units of a size for people (Story 2.6), smallest first, 1024 apart.
export const byteUnitLabels = ['B', 'KB', 'MB', 'GB', 'TB'] as const;

// Labels of the session-expiry warning (Story 1.15). Its message (`session-warning`) and the toast after
// signing back in (`session-expired`) come from the catalogue.
export const sessionLabels = {
    title: 'Your session is about to end',
    stay: 'Stay signed in',
    signOut: 'Sign out',
    extendFailed: "We couldn't keep you signed in. Try again.",
} as const;

// Wizard Step 2, Data Source (Story 3.7). `post-readonly`, `live-not-supported`, `datasource-none` and `perm-denied` come from the catalogue;
// the parameter table reuses `endpointLabels` (column names, binding names, the user-context tag and shared-data badge).
export const blockSourceLabels = {
    title: 'Data source',
    caption: 'Which API this block reads, and how often.',
    sourceSection: { title: 'Data source and endpoint' },
    paramsSection: {
        title: 'Parameters',
        caption: 'How each parameter of the endpoint gets its value.',
    },
    refreshSection: {
        title: 'Refresh and date range',
        caption: 'How often the data is fetched and the period it starts on.',
    },
    dataSource: 'Data source',
    dataSourcePlaceholder: 'Choose a data source',
    // A select option is plain text: the name and the health word.
    sourceOption: (name: string, health: string) => `${name} (${health})`,
    endpoint: 'Endpoint',
    endpointPlaceholder: 'Choose an endpoint',
    endpointOption: (method: string, path: string) => `${method} ${path}`,
    noEndpoints: 'This data source has no endpoints yet.',
    editSource: 'Edit data source',
    editSourceHint: 'Opens in a new tab.',
    registerHint: 'Opens in a new tab.',
    newTab: '(opens in a new tab)',
    chooseSource: 'Choose a data source first.',
    chooseEndpoint: 'Choose an endpoint first.',
    postRequired:
        'Mark this POST as a read-only query before you fetch or continue.',
    pathEmpty: (names: string[]) =>
        names.length === 1
            ? `Enter a value for the path parameter ${names[0]}.`
            : `Enter a value for the path parameters ${names.join(', ')}.`,
    parametersCaption: 'Parameters of the endpoint',
    noParams: 'This endpoint has no parameters.',
    paramsNone: 'Choose an endpoint to see its parameters.',
    valueLabel: (name: string) => `Value of ${name}`,
    periodUnavailable: 'Needs "Own selector" under Period behavior in step 1.',
    pinned: (revision: number) => `Pinned to data source revision ${revision}.`,
    outOfDateTitle: 'The sample is out of date',
    outOfDate:
        'The data source, endpoint or a parameter changed. Fetch or paste a new sample, or confirm the one you have, before you map the data.',
    // Fetch from API (Story 3.9): the button, and the two reasons a request cannot be made until the default range is chosen.
    fetch: 'Fetch sample',
    rangeNeeded:
        'Choose a default date range under Refresh and date range before you fetch: the endpoint needs dates.',
    periodNeeded:
        'This endpoint uses the block period, which needs "Own selector" under Period behavior in step 1, before you fetch.',
    loading: 'Loading data sources…',
    loadFailed: "We couldn't load the data sources.",
    retry: 'Try again',
    // Refresh Interval.
    refresh: 'Refresh interval',
    refreshPlaceholder: 'Choose how often to refresh',
    live: 'Live',
    daily: 'Daily',
    days: (n: number) => `Every ${n} days`,
    minutes: (n: number) => (n === 1 ? '1 minute' : `${n} minutes`),
    seconds: (n: number) => (n === 1 ? '1 second' : `${n} seconds`),
    refreshNone:
        'No refresh intervals are set up in System settings yet, so none can be chosen.',
    refreshFailed: "We couldn't load the refresh intervals.",
    // Default date range.
    range: 'Default date range',
    rangePlaceholder: 'Choose a default date range',
    presets: {
        today: 'Today',
        this_week: 'This week',
        this_month: 'This month',
        current_year: 'Current year',
        custom: 'Custom',
    } as Record<string, string>,
    from: 'From',
    to: 'To',
    rangeOrder: 'The start date cannot be after the end date.',
    // The out-of-date flag and the pins in words, for a screen reader.
    sourceHealthLabel: 'Health',
} as const;

// The Sample Response of wizard Step 2 (Story 3.8, paste). `json-valid`, `json-invalid` and `sample-wizard` are catalogue messages; the rest is
// control vocabulary. Nothing here ever quotes the sample.
export const blockSampleLabels = {
    title: 'Sample response',
    caption:
        'A sample shows the structure of the response, so you can map it. It is used for mapping and preview only.',
    modeLabel: 'Where the sample comes from',
    fetchOption: 'Fetch from API',
    pasteOption: 'Paste sample JSON',
    textLabel: 'Sample JSON',
    textHint:
        'Paste a response from your API. The server reads it exactly, so every digit of a number is kept.',
    checking: 'Checking the JSON…',
    validNoRecords: (time: string, bytes: number, shape: string) =>
        `✓ Valid JSON · no list of records found · Pasted ${time} · ${bytes} bytes · ${shape}`,
    goToLine: (line: number) => `Go to line ${line}`,
    tooLarge: (size: number, limit: number) =>
        `This sample is ${size} bytes and the limit is ${limit} bytes, so it was rejected. Nothing was stored.`,
    tooDeep: (limit: number) =>
        `This sample is nested more than ${limit} levels deep, which is the limit, so it was rejected. Nothing was stored.`,
    notConfigured: (setting: string | null) =>
        setting === 'depth_limit'
            ? 'The nesting limit is not set in System settings, so a sample cannot be accepted. Ask an operator to set it.'
            : 'The size limit is not set in System settings, so a sample cannot be accepted. Ask an operator to set it.',
    checkFailed: "We couldn't check the JSON. Try again.",
    recheck: 'Check again',
    ackLabel: 'This sample may contain personal data',
    ackHint:
        'It is stored encrypted, for this draft only, and deleted automatically. It is never used as a live source.',
    ackRequired: 'Confirm that the sample may contain personal data.',
    use: 'Use this sample',
    useReplace: 'Replace the sample',
    saving: 'Saving the sample…',
    needValid: 'Paste valid JSON first.',
    needAck: 'Confirm the personal data box first.',
    needLock: 'You are not editing this draft, so the sample cannot be saved.',
    ttlNotConfigured:
        'The sample lifetime is not set in System settings, so a sample cannot be stored. Ask an operator to set it.',
    storeFailed: "We couldn't save the sample. Try again.",
    storeLocked:
        'You no longer hold the edit lock on this draft, so the sample was not saved.',
    unavailable:
        'The sample cannot be saved or opened right now. Ask an operator to check the encryption key.',
    accepted: 'Sample saved.',
    inUse: 'Sample in use',
    pastedAt: (time: string) => `Pasted ${time}`,
    summary: (time: string, bytes: number) => `Pasted ${time} · ${bytes} bytes`,
    // Fetch from API (Story 3.9). `fetch-ok`, `host-not-allowlisted`, `blocked-address`, `response-too-large`, `not-json`, `fetch-failed` and
    // `offline-editing` are catalogue messages; the rest is control vocabulary.
    fetchOkNoRecords: (status: number, ms: number) =>
        `✓ ${status} · ${ms} ms · no list of records found`,
    fetching: 'Fetching the sample…',
    fetchedAt: (time: string) => `Fetched ${time}`,
    fetchedSummary: (time: string, bytes: number) =>
        `Fetched ${time} · ${bytes} bytes`,
    fetchFailedTitle: 'Fetch failed',
    pasteInstead: 'Paste sample JSON instead',
    fetchExpired:
        'The fetch took too long or expired. Nothing was stored. Try again.',
    fetchStale:
        'This result is not current: someone else took over editing this draft while the fetch ran. Nothing was stored.',
    fetchRetry: 'Retry',
    waitFor: (seconds: number) =>
        `Too many fetches. Try again in ${seconds} ${seconds === 1 ? 'second' : 'seconds'}.`,
    waitButton: (seconds: number) => `Wait ${seconds} s`,
    earlierParameters:
        'This sample belongs to earlier parameters. Fetch again to get one for the current ones.',
    fetchAckHint:
        'The response is stored encrypted, for this draft only, and deleted automatically.',
    settingNotConfigured: (setting: string | null) =>
        setting
            ? `${setting} is not set, so a sample cannot be fetched. Ask an operator to set it.`
            : 'A setting that fetching needs is not set. Ask an operator to set it.',
    endpointGone:
        'The data source or endpoint no longer exists. Choose another one.',
    fetchRefused: "The sample can't be fetched with these values.",
    fetchStartFailed: "We couldn't start the fetch. Try again.",
    fetchPollFailed: "We couldn't read the result of the fetch. Try again.",
    restoreFailed: "We couldn't load the sample saved with this draft.",
    retry: 'Try again',
    invalidBlocked:
        'Fix the JSON in the sample, or switch back to fetching, before you continue.',
    uncheckedBlocked:
        'Wait for the sample JSON to be checked, or clear it or switch back to fetching, before you continue.',
    // The structure view.
    structure: 'Structure of the sample',
    view: 'View',
    viewTree: 'Tree',
    viewTable: 'Table',
    viewJson: 'View JSON',
    hideJson: 'Hide JSON',
    columns: { path: 'Path', type: 'Type', example: 'Example' },
    expand: (path: string) => `Expand ${path}`,
    collapse: (path: string) => `Collapse ${path}`,
    records: (count: number) => (count === 1 ? '1 item' : `${count} items`),
    tableOf: 'Records to show as a table',
    tableCaption: (path: string) => `Records at ${path}`,
    tableTruncated: (shown: number, total: number) =>
        `Showing the first ${shown} of ${total} records.`,
    noTables: 'This sample has no list of records to show as a table.',
    nodes: 'Fields in the sample',
} as const;

// Map Data (Story 3.11b, stage 1: the shell and the Roles section; UX-DR-65, 66, 123, 125 to 129, 180 to 182). `map-small-screen` is a catalogue message;
// the rest is control vocabulary. Nothing here ever quotes the sample.
export const blockMapLabels = {
    region: 'Map Data',
    loading: 'Reading the field roles…',
    loadFailed: "We couldn't read the mapping for this draft.",
    retry: 'Try again',
    notSaved: 'The change was not saved. Try again.',
    refreshFailed:
        "We couldn't refresh the mapping. What you see may be out of date.",
    summaryLabel: (n: number, label: string) => `Step ${n} · ${label}`,
    // The recovery message when the sample has ended or cannot be read.
    sampleGoneTitle: 'This draft has no usable sample',
    sampleExpired:
        'The sample for this draft has expired, so there is nothing to map. Go back to Data Source to fetch or paste it again.',
    sampleInvalid:
        'The sample for this draft cannot be read. Go back to Data Source to fetch or paste it again.',
    toDataSource: 'Back to Data Source',
    noFields: 'This sample has no fields to map.',
    // The status bar.
    statusBar: 'Mapping status',
    statusSummary: (
        autoMapped: number,
        total: number,
        confirmed: number,
        requiredMissing: number,
    ) =>
        `Auto-mapped ${autoMapped} of ${total} · ${confirmed} confirmed · ${requiredMissing} required missing`,
    statusSubtitle: 'Fields → slots of your KPI block',
    // The three sections.
    rolesTitle: '1 · Roles',
    rolesCaption: 'What does each field mean?',
    slotsTitle: '2 · Review block slots',
    slotsCaption: 'Which field fills each part of the block.',
    transformsTitle: '3 · Transforms',
    transformsCaption: 'Optional steps that reshape the rows.',
    transformsPlaceholder:
        'Transforms arrive in a later update. There is nothing to set here yet.',
    // Roles.
    roleNames: {
        label: 'Label',
        value: 'Value',
        dimension: 'Dimension',
        measure: 'Measure',
        time: 'Time',
        filter: 'Filter/Category',
    } as Record<string, string>,
    roleDescriptions: {
        label: 'Names or describes something.',
        value: 'One headline number.',
        dimension: 'Groups or splits the rows.',
        measure: 'A number to plot or add up.',
        time: 'When something happened.',
        filter: 'Lets people narrow the data.',
    } as Record<string, string>,
    roleQualifiers: {
        main: 'main',
        baseline: 'baseline',
        unit: 'unit',
        asOf: 'as-of',
    },
    chipName: (field: string, role: string, flagged: boolean) =>
        `Role for ${field}: ${role}${flagged ? ', possible wrong role' : ''}`,
    chipMenu: (field: string) => `Roles for ${field}`,
    roleChanged: (field: string, role: string) =>
        `Role for ${field} is now ${role}.`,
    undoNote: (field: string, role: string) => `${field} is now ${role}.`,
    undo: 'Undo',
    undone: (field: string, role: string) =>
        `Undone. Role for ${field} is ${role} again.`,
    scalarsTitle: 'Single values',
    noScalars: 'No single values outside the list of records.',
    scalarList: 'Single values in the response',
    exampleNone: '(no example)',
    typeOf: (type: string) => `Type: ${type}`,
    exampleOf: (example: string) => `Example: ${example}`,
    tableRegion: 'Sample rows, scrolls sideways',
    tableCaption: (path: string) => `Sample rows of ${path}`,
    tableHeading: 'Sample rows',
    suggestion: (field: string, role: string) =>
        `✦ ${field} looks like a ${role} — use as series?`,
    setAs: (role: string) => `Set as ${role}`,
    keepAs: (role: string) => `Keep as ${role}`,
    looksRight: 'Looks right',
    editRoles: 'Edit roles',
    rolesAccepted: 'Roles accepted. Roles table collapsed.',
    summaryTitle: 'Roles accepted',
    summaryNone: 'No fields have a role yet.',
    saving: 'Saving the role…',
    // The record path.
    recordPath: 'Rows from (record path)',
    recordPathHint:
        'This response has more than one list. Choose the one that holds the rows.',
    rowsColumns: (rows: number, columns: number) =>
        `${rows} ${rows === 1 ? 'row' : 'rows'} · ${columns} ${columns === 1 ? 'column' : 'columns'}`,
    suggested: 'Suggested',
    paginationLike: 'looks like pagination, not data',
    recordPathChanged: (path: string) => `Rows now come from ${path}.`,
    // The slot checklist (Story 3.12b).
    checklist: 'Block slots',
    groupNames: {
        HEADER: 'Header',
        KPI: 'KPI',
        META: 'Meta',
    } as Record<string, string>,
    requiredMark: '*',
    slotRow: (slot: string, required: boolean, state: string, level: string) =>
        `${slot}, ${required ? 'required' : 'optional'}, ${state}, ${level} confidence`,
    stateNames: {
        auto_high: 'Auto-mapped',
        auto_medium: 'Auto-mapped',
        auto_low: 'Auto-mapped',
        confirmed: 'Confirmed',
        overridden: 'Overridden',
        optional_empty: 'Optional, empty',
        required_missing: 'Required, missing',
        type_mismatch: 'Type mismatch',
    } as Record<string, string>,
    confidenceWords: {
        high: 'High',
        medium: 'Medium',
        low: 'Low',
        none: 'No',
    } as Record<string, string>,
    confidenceBadge: (word: string) => `Confidence: ${word}`,
    confirmedByYou: 'Confirmed by you',
    confirm: 'Confirm',
    confirmFor: (slot: string) => `Confirm ${slot}`,
    noField: 'No field mapped',
    exampleShort: (example: string) => `e.g. ${example}`,
    useAs: (field: string, slot: string) => `Use ${field} as ${slot}`,
    baselineNote: (fields: string) =>
        `Baseline field, shown but not mapped: ${fields}`,
    filterLabel: 'Show slots',
    filterAll: (n: number) => `All (${n})`,
    filterReview: (n: number) => `Needs review (${n})`,
    filterMissing: (n: number) => `Missing (${n})`,
    filterEmpty: 'No slots match this filter.',
    rerun: '↻ Re-run auto-map',
    rerunNote: 'Confirmed and changed slots stay as they are.',
    rerunDone: 'Auto-map ran again. Confirmed and changed slots were kept.',
    confirmedAnnounce: (slot: string, left: number) =>
        `${slot} confirmed. ${left} ${left === 1 ? 'slot still needs' : 'slots still need'} review.`,
    checklistMissing:
        'The slot checklist is not ready yet. Try again in a moment.',
    blockerOtherShort: 'needs a look',
    blockerOther: (slot: string) => `Look at ${slot} before you continue.`,
    sampleBadge: 'Sample data',
    continueToPreview: 'Continue to Preview →',
    blockerUnconfirmed: (slot: string) =>
        `Confirm ${slot}, its mapping is a low-confidence guess.`,
    blockerMismatch: (slot: string) => `Fix ${slot}, its field does not fit.`,
    blockerChanged: (slot: string) =>
        `Review ${slot}, its field is no longer in the response.`,
    // The field picker, override with Undo, Treat as and API-change messages (Story 3.13b).
    changeField: 'Change field',
    cannotChange: 'This slot cannot be changed right now.',
    changeFieldFor: (slot: string) => `Change field for ${slot}`,
    pickerLabel: (slot: string) => `Fields for ${slot}`,
    pickerSearch: 'Search fields',
    pickerSearchHint: 'Search by name, type or role',
    fieldsMatch: (n: number) =>
        `${n} ${n === 1 ? 'field matches' : 'fields match'}`,
    pickerLoading: 'Reading the fields…',
    pickerFailed: "We couldn't list the fields.",
    pickerEmpty: 'No fields match.',
    overriddenNote: (field: string, role: string, old: string) =>
        `${field} is now a ${role} (was ${old}).`,
    overriddenPlain: (field: string | null, slot: string) =>
        field === null ? `${slot} was cleared.` : `${slot} now uses ${field}.`,
    undoFor: (slot: string) => `Undo the change to ${slot}`,
    undoneSlot: (slot: string) => `Undone. ${slot} is back as it was.`,
    overriddenAnnounce: (slot: string, field: string | null) =>
        field === null ? `${slot} cleared.` : `${slot} now uses ${field}.`,
    undoStale:
        'That slot changed after the override, so it was not undone. The list is up to date.',
    notPicked: "That field can't be used for this slot.",
    typeMismatchPill: 'Type mismatch',
    fitsHeading: 'Fields that fit:',
    useField: (field: string) => `Use ${field}`,
    useFieldFor: (field: string, slot: string) => `Use ${field} for ${slot}`,
    treatAs: 'Treat as…',
    treatAsFor: (field: string) => `Treat ${field} as…`,
    treatAsMenu: (field: string) => `Treat ${field} as`,
    typeNames: {
        text: 'Text',
        number: 'Number',
        boolean: 'True or false',
        date: 'Date',
    } as Record<string, string>,
    typeAutomatic: 'Automatic',
    treatedAs: (field: string, type: string) =>
        `${field} is now treated as ${type}.`,
    treatedAuto: (field: string) =>
        `${field} is read as its detected type again.`,
    notTreated: "That type can't be set for this field.",
    apiMatchFound: (field: string, value: string | null) =>
        `Dashflow found a new field ${field} that looks like the same data${value ? ` (matching value ${value})` : ''}.`,
    useMatchFor: (match: string, slot: string) => `Use ${match} for ${slot}`,
    blockerShort: {
        required_missing: 'needs a field',
        unconfirmed_low: 'needs your confirmation',
        type_mismatch: 'its field does not fit',
        api_changed: 'its field is no longer in the response',
    } as Record<string, string>,
    // The searchable field tree (Story 3.11d).
    treeBrowse: 'Browse all fields',
    treeLabel: 'Fields in the sample',
    treeSearch: 'Search fields',
    treeSearchHint: 'Search by name, type or example',
    treeExpandAll: 'Expand all',
    treeCollapseAll: 'Collapse all',
    treeEmpty: 'No fields match your search.',
    treeBreadcrumb: 'Field path',
    treeUseFor: 'Use for',
    treeUse: 'Use',
    treeUseAria: (field: string, slot: string) => `Use ${field} for ${slot}`,
    treeItemName: (field: string, type: string) => `${field}, ${type}`,
    treeSelected: (
        path: string,
        example: string | null,
        formatted: string | null,
    ) =>
        `Selected: ${path}${example === null ? '' : ` · ${example}`}${formatted === null ? '' : ` → ${formatted}`}`,
    treeFocused: (
        path: string,
        example: string | null,
        formatted: string | null,
    ) =>
        `Focused: ${path}${example === null ? '' : ` · ${example}`}${formatted === null ? '' : ` → ${formatted}`}`,
    treeNothingSelected: 'Selected: none yet',
    treeStillLoading: 'Still checking which fields fit. Try again in a moment.',
    treeBusyNow: 'A change is being saved. Try again in a moment.',
    treeNotAField: "This can't feed a slot on its own.",
    treeLoading: 'Checking which fields fit…',
    treeFailed: "We couldn't check which fields fit this slot.",
    treeChoose: 'Choose from the field tree',
    treeChooseFor: (slot: string) => `Choose ${slot} from the field tree`,
    // The Datasets suggestion in the record-path list.
    datasetUsedEarlier: 'Used in an earlier block',
    datasetNotCandidate: (path: string) =>
        `${path} was used in an earlier block but is not in this response.`,
} as const;

// The live preview on the Map Data step (Story 3.14, UI stage 4): the preview region, its hotspots, the source legend and the notices.
export const blockPreviewLabels = {
    region: 'Live preview, sample data',
    skipToChecklist: 'Skip to slot checklist',
    sampleLabel: 'Sample data',
    // A hotspot over a mapped region of the card.
    hotspotName: (slot: string, field: string, value: string) =>
        `${slot}: ${field}, ${value}. Change field`,
    hotspotTip: (slot: string, field: string) =>
        `Click to change field · ${slot} ← ${field}`,
    // The hatched placeholder for a required slot with nothing mapped.
    missingName: (slot: string) =>
        `${slot}, required, not mapped. Choose field`,
    missingText: (slot: string, role: string) =>
        `${slot} required · No ${role} mapped · click to choose`,
    // The source legend.
    legendTitle: 'Where each part comes from',
    legendEmpty: 'Nothing is mapped yet.',
    noEndpoint: 'the endpoint',
    unavailable: 'Unavailable',
    unavailableReasons: {
        missing: 'the field is not in the sample',
        not_single_value: 'the field has more than one value',
        wrong_type: 'the field is not a single value',
    } as Record<string, string>,
    unavailableWhy: (reason: string) => `Unavailable (${reason})`,
    // Notices: quiet, polite, and never in the way.
    stale: 'The mapping was made for an earlier sample. Re-run auto-map to bring it up to date.',
    throttled: (seconds: number | null) =>
        seconds === null
            ? 'The preview is paused for a moment. Showing the last one.'
            : `The preview is paused for a moment. Showing the last one; try again in ${seconds} ${seconds === 1 ? 'second' : 'seconds'}.`,
    guard: 'The preview stopped because the sample went over a safety limit. Showing the last one.',
    setting: (setting: string | null) =>
        setting === null
            ? 'The preview is not set up on this server: a required setting has no value. Ask your operator to configure it.'
            : `The preview is not set up on this server: the setting ${setting} has no value. Ask your operator to configure it.`,
    noSample: 'There is no sample to preview. Go back to Data Source.',
    failed: "We couldn't refresh the preview. Showing the last one.",
    failedEmpty: "We couldn't build the preview.",
    // A read-only view still shows what is mapped.
    readOnlyRegions: 'Read-only: fields cannot be changed here.',
} as const;

// The presentation rules editor on the Map Data step (Story 3.15b): format, emphasis, direction, threshold bands, labels and the empty display.
export const blockPresentationLabels = {
    open: 'Format and display',
    openFor: (slot: string) => `Format and display for ${slot}`,
    close: 'Hide format and display',
    heading: (slot: string) => `Format and display · ${slot}`,
    // The three columns.
    formatTitle: 'Format',
    emphasisTitle: 'Emphasis',
    directionTitle: 'Direction',
    kind: 'Show as',
    kinds: {
        number: 'Number',
        integer: 'Integer',
        percent: 'Percent',
        currency: 'Currency',
        compact: 'Compact (1.2K)',
        date: 'Date',
        'relative-time': 'Relative time',
        duration: 'Duration',
    } as Record<string, string>,
    places: 'Decimal places',
    placesHint: 'Leave empty to keep every digit',
    thousands: 'Thousands separator',
    currencyCode: 'Currency code',
    currencyCodeHint: 'Three letters, such as USD',
    currencyDisplay: 'Show currency as',
    currencyDisplays: { symbol: 'Symbol ($)', code: 'Code (USD)' } as Record<
        string,
        string
    >,
    datePattern: 'Date pattern',
    datePatterns: {
        short: 'Short',
        medium: 'Medium',
        long: 'Long',
        full: 'Full',
    } as Record<string, string>,
    units: 'Units suffix',
    unitsHint: 'Such as kg or items',
    example: 'Example',
    exampleValue: (shown: string) => `Example: ${shown}`,
    exampleNone: 'Example: complete the format to see one',
    noFormat: 'Text is shown as it is. There are no format options.',
    // Emphasis.
    role: 'Role',
    roles: {
        headline: 'Headline',
        primary: 'Primary',
        secondary: 'Secondary',
        badge: 'Badge',
    } as Record<string, string>,
    size: 'Size',
    sizes: { sm: 'Small', md: 'Medium', lg: 'Large' } as Record<string, string>,
    weight: 'Weight',
    weights: { regular: 'Regular', medium: 'Medium', bold: 'Bold' } as Record<
        string,
        string
    >,
    notSet: 'Card default',
    // Direction.
    direction: 'Direction',
    directions: {
        higher_is_better: 'Higher is better',
        lower_is_better: 'Lower is better',
    } as Record<string, string>,
    directionNote: 'Colors the comparison: up = green',
    directionNoteLower:
        'Colors the comparison: down = green · better, up = red · worse',
    better: 'better',
    worse: 'worse',
    directionComparisonOnly: 'Direction applies to the comparison slot only.',
    directionNotHere: 'Direction applies to the comparison slot only.',
    // Labels.
    labelText: 'Label text',
    labelHint: 'Shown with the value',
    prefix: 'Prefix',
    suffix: 'Suffix',
    // Bands.
    bandsTitle: 'Threshold bands',
    bandsCaption:
        'The first band that matches, from the top, colors the status badge.',
    addBand: '+ Add band',
    removeBand: (n: number) => `Remove band ${n}`,
    bandName: (n: number) => `Band ${n}`,
    bandOrder: (n: number) => `${n}`,
    bandCondition: 'Condition',
    bandOps: {
        '>=': '≥ at least',
        '<=': '≤ at most',
        between: 'between',
    } as Record<string, string>,
    bandValue: 'Value',
    bandTo: 'and',
    bandColor: 'Color',
    colors: {
        success: 'Success',
        warning: 'Warning',
        error: 'Error',
        info: 'Info',
        neutral: 'Neutral',
    } as Record<string, string>,
    bandIcon: 'Icon',
    bandIconNone: 'Choose an icon',
    bandLabel: 'Label',
    bandsNone: 'No bands yet. The status badge shows the mapped text, if any.',
    bandIconNeeded: 'A band needs an icon.',
    bandIconUnknown: 'Choose an icon from the list.',
    bandLabelNeeded: 'A band needs a label.',
    colorNeeded: 'Choose a color.',
    decimalNeeded: 'Use a number, such as 100 or 2.5.',
    bandsMax: (n: number) => `Use at most ${n} bands.`,
    // The empty display.
    emptyTitle: 'Empty display',
    emptyHint:
        'Shown for a mapped value that is missing. Never used for the headline.',
    emptyForHeadline:
        'The headline shows Unavailable when it has no value, never an empty display.',
    // Faults and states.
    textLimit: (max: number) =>
        `Use 1 to ${max} characters, with no control characters.`,
    placesRange: (max: number) => `Use a whole number from 0 to ${max}.`,
    currencyShape: 'Use a three-letter currency code, such as USD.',
    currencyNeeded: 'A currency format needs a currency code.',
    unknownSlot: 'This slot is not part of the block.',
    invalid: 'This value is not valid.',
    errorsTitle: 'Fix the format and display settings to continue.',
    blockedContinue:
        'Fix the format and display settings (marked below) to continue to Preview.',
    saved: 'Format and display saved.',
    notSaved: 'The format and display settings were not saved.',
    refused:
        'The format and display settings were refused. Check the marked fields.',
    readOnly: 'Read-only: format and display cannot be changed here.',
} as const;

// Step 4, Preview (Story 3.16): the Block on every device and state, in a sample dashboard grid, and the warnings the Admin acknowledges.
export const blockPreviewStepLabels = {
    title: 'Preview on every device and state',
    caption:
        'See the block as people will, at its configured size, and review anything worth a second look.',
    sampleLabel: 'Sample data',
    states: 'Preview state',
    stateOptions: [
        { value: 'normal', label: 'Normal' },
        { value: 'loading', label: 'Loading' },
        { value: 'empty', label: 'Empty' },
        { value: 'stale', label: 'Stale' },
        { value: 'unavailable', label: 'Unavailable' },
        { value: 'minimized', label: 'Minimized' },
    ] as { value: string; label: string }[],
    fullSizeTitle: 'Full size',
    gridTitle: 'In a sample dashboard',
    gridCaption: (columns: number, gap: number) =>
        `12 columns, ${gap} px gap. The block takes ${columns} ${columns === 1 ? 'column' : 'columns'}.`,
    gridRegion: (device: string) => `Sample dashboard grid, ${device}`,
    otherBlock: 'Other block',
    minimizedHint: 'Minimized: only the title bar shows on a dashboard.',
    minimizedBar: (title: string) => `${title} (minimized)`,
    contentScrolls: 'Long content scrolls inside the block.',
    loading: 'Building the preview.',
    // Warnings.
    warningsTitle: 'Warnings',
    noWarnings: 'No warnings. The block is ready to continue.',
    warningsCount: (open: number, total: number) =>
        open === 0
            ? `All ${total} ${total === 1 ? 'warning is' : 'warnings are'} acknowledged.`
            : `${open} of ${total} ${total === 1 ? 'warning needs' : 'warnings need'} acknowledging.`,
    acknowledge: 'Acknowledge',
    clearedOptional: (slot: string) =>
        `${slot} is optional and has no field mapped: a suggested field was left out. The block shows nothing for it.`,
    nullValue: (slot: string, field: string) =>
        `${slot} is optional and the sample has no value for ${field}. The block shows its empty display, or nothing.`,
    unavailableValue: (slot: string, field: string, why: string) =>
        `${slot} is mapped to ${field} but unavailable (${why}).`,
    staleMapping:
        'The mapping was made for an earlier sample. Re-run auto-map in Map Data to bring it up to date.',
    // Continue.
    blockedUnresolved: (open: number) =>
        `Acknowledge ${open} ${open === 1 ? 'warning' : 'warnings'} to continue.`,
    blockedNotReady: 'Wait for the preview to load before continuing.',
    blockedNoPreview:
        'The preview could not be built, so its warnings cannot be checked. Go back to Map Data.',
    back: 'Back',
    continue: 'Continue',
    readOnly: 'Read-only: warnings cannot be acknowledged here.',
    announceAcknowledged: (open: number) =>
        open === 0
            ? 'All warnings acknowledged. Continue is available.'
            : `${open} ${open === 1 ? 'warning' : 'warnings'} left to acknowledge.`,
} as const;

// Step 5, Validate (Story 3.18): the Draft checked against the live API, the version it will create and whether Publish is allowed. The messages
// `live-shape-unreachable` and `version-safe` come from the catalogue.
export const blockValidateStepLabels = {
    title: 'Validate against the real API',
    caption:
        'Dashflow calls your endpoint once, live, and checks that every mapped field is still there with the right type. A pasted sample is never accepted as proof.',
    versionTitle: 'Version to create',
    versionValue: (version: string) => `Version ${version}`,
    validate: 'Validate',
    validateAgain: 'Validate again',
    running: 'Checking the live response.',
    startedAnnounce: 'Validation started.',
    back: 'Back',
    saveDraft: 'Save draft',
    publish: 'Publish',
    // Publish readiness.
    readyTitle: 'Ready to publish',
    readyBody: (version: string) =>
        `The live response matches this block. Publishing creates version ${version}.`,
    notReadyTitle: 'Publish is blocked',
    publishReady: 'Publish block makes it live in the Block Library.',
    publishing: 'Publishing…',
    publishStale:
        'The draft changed while it was saved. Validate again, then publish.',
    publishNotReady:
        'The block has not passed validation for its latest changes. Validate again, then publish.',
    publishFailed: 'The block could not be published. Try again.',
    publishDenied: "You don't have permission to publish this block.",
    publishNoDraft: 'Save the draft first, then publish.',
    publishFieldsUnresolved: 'Refresh the sample, then publish again.',
    publishTypeUnavailable:
        'This block type is no longer available, so the block cannot be published.',
    publishPending:
        'Your block was saved as ready; it will go live shortly. Publish again to finish it now.',
    blockedNoSource:
        'Choose a real data source and endpoint in Data Source. A pasted sample is never accepted as proof.',
    pastedOnly:
        'This block only has a pasted sample. Choose a real data source and endpoint, then validate.',
    blockedNotValidated: 'Validate the block against the real API to continue.',
    blockedFailed:
        'The live response does not match the mapping. Fix the fields listed, then validate again.',
    blockedStrict:
        'The draft is not complete. Fix what is listed, then validate again.',
    blockedUnreachable: 'The live API could not be reached.',
    blockedStale:
        'The draft changed after it was last validated. Validate again.',
    blockedRunning: 'Wait for the validation to finish.',
    // Results.
    passedTitle: 'Validated',
    passedBody: (version: string) =>
        `The live response matches. Version ${version} is ready to create.`,
    failedTitle: 'The live response does not match',
    strictTitle: 'The draft is not complete',
    staleTitle: 'Validate again',
    staleBody:
        'The draft changed after the last validation, or while it ran. Validate again to check it.',
    unreachableTitle: 'The API could not be reached',
    unboundTitle: 'This endpoint could be published without a member',
    unboundBody:
        "A request without the member's attribute was not refused. Check the endpoint's user bindings.",
    contextMissingTitle: 'The live check could not run as you',
    contextMissingBody:
        'This endpoint uses user context and your account lacks a value it needs, so no request was made. Ask a colleague to set it, then validate again.',
    mismatchesTitle: 'Fields to fix',
    mismatch: {
        missing: (slot: string, path: string) =>
            `${slot}: ${path} is not in the live response.`,
        wrong_type: (slot: string, path: string, expected: string) =>
            `${slot}: ${path} is not a ${expected} in the live response.`,
        not_single_value: (slot: string, path: string) =>
            `${slot}: ${path} matches more than one value in the live response.`,
        required_slot_unmapped: (slot: string) =>
            `${slot} is required and is not mapped. Map it in Map Data.`,
        no_plan: 'Nothing is mapped yet. Map the fields in Map Data.',
        invalid: (path: string) => `${path} is not valid.`,
    },
    // Why a validation was not started or could not finish.
    refusedNoSource:
        'There is no real data source and endpoint to validate against.',
    settingNotConfigured: (setting: string | null) =>
        setting === null
            ? 'A setting validation needs is not configured.'
            : `${setting} is not configured, so the block cannot be validated.`,
    startFailed: 'Validation could not start. Try again.',
    checkFailed:
        'Validation could not finish. Try again, or save the draft and come back.',
    expired: 'The validation ran out of time. Validate again.',
    throttled: (seconds: number) =>
        `Too many validations. Try again in ${seconds} ${seconds === 1 ? 'second' : 'seconds'}.`,
    loadFailed: 'The last validation could not be loaded.',
    validatedAt: (time: string) => `Validated ${time}`,
    unreachableReason: (reason: string | null) => {
        const known: Record<string, string> = {
            'host-not-allowlisted':
                'The data source host is not on the allowlist.',
            'blocked-address':
                'The data source address is blocked by the network guard.',
            'fetch-failed': 'The API did not answer successfully.',
            'not-json': 'The API did not answer with JSON.',
            'response-too-large': 'The response was larger than the limit.',
            'auth-failed': 'The API refused the credentials.',
            'too-many-pages': 'The response needs more pages than allowed.',
            blob_unavailable: 'The response could not be kept for checking.',
        };

        return reason === null
            ? ''
            : (known[reason] ?? 'The API could not be checked.');
    },
    guardExceeded:
        'The check passed a safety limit and was stopped. Simplify the mapping or ask an operator to review the limits.',
    settingMissing:
        'A setting the check needs is not configured. Ask an operator, then validate again.',
    invalidInput:
        'The check could not be built from this draft. Validate again.',
    engineError: 'The check hit an unexpected error. Try again.',
} as const;

// Admin > Published blocks (Story 3.19; UX-DR-261, 263, 282): the minimal list of what is live. Publish impact, restore and unpublish are Epic 6.
export const publishedBlockLabels = {
    pageTitle: 'Published blocks',
    pageSubtitle: 'Blocks that are live in the Block Library.',
    items: 'published blocks',
    action: 'Create a block',
    caption:
        'Published blocks of this workspace, with category, type, version, owner and last update',
    tableRegion: 'Published blocks table',
    columns: {
        name: 'Name',
        category: 'Category',
        type: 'Type',
        version: 'Version',
        owner: 'Owner',
        updated: 'Last updated',
        status: 'Status',
    },
    status: 'Published',
    noCategory: '—',
    noOwner: '—',
    types: { 'kpi-card': 'KPI card' } as Record<string, string>,
    highlighted: (name: string) => `${name} is now published.`,
} as const;

// Fetch as user and Preview as in the Create block wizard (Story 3.17). The failure messages are the shared error card's; these are the panel's own.
export const blockAsUserLabels = {
    fetchTitle: 'Fetch as user',
    fetchCaption:
        'Sends this request once as the member you choose, using their own ID, email, group and attributes, to see the shape of what they would get. The response is kept for a few minutes, only for you, and is never saved to the draft. An audit event is recorded for each use.',
    previewTitle: 'Preview as',
    previewCaption:
        'Show this block with the data a member would get. The preview is computed from a fetch made as that member, kept for a few minutes, only for you.',
    search: 'Search members',
    member: 'Member',
    choose: 'Choose a member',
    none: 'No active members match.',
    groups: (names: string[]) => `Groups: ${names.join(', ')}`,
    fetch: 'Fetch as user',
    previewAs: 'Preview as',
    refetch: 'Fetch again',
    noPermission:
        "You don't have permission to fetch or preview as a user. Ask an admin to grant Preview as user.",
    memberRequired: 'Choose a member first.',
    waitFor: (seconds: number) => `Wait ${seconds}s`,
    running: (name: string) => `Fetching as ${name}…`,
    failedTitle: 'Fetch as user failed',
    startFailed: "We couldn't start the fetch. Try again.",
    memberGone: 'This member is no longer in the workspace. Choose another.',
    refused: "That request can't be sent. Check the parameters.",
    membersFailed: "We couldn't load the members. Try again.",
    sampleData: 'Sample data',
    done: (name: string, until: string) =>
        `Fetched as ${name}. Sample data, kept only for you until ${until}.`,
    structureTitle: (name: string) => `Structure of ${name}'s response`,
    structureNote:
        'Paths and types only. The values stay on the server and are never saved.',
    previewingAs: (name: string) => `Previewing as ${name}`,
    earlier:
        'This result was taken with earlier parameters. Fetch again to preview as the member.',
    expired:
        'The fetched user data has expired. Fetch again to preview as the member.',
    ended: 'The fetched user data expired, so the preview shows the shared sample again.',
    stale: 'The endpoint changed while this was fetched, so the result was dropped. Fetch again.',
    denied: 'You no longer have permission to preview as a user, so the preview shows the shared sample.',
    sourceOptions: [
        { value: 'shared', label: 'Shared sample' },
        { value: 'user', label: 'As the member' },
    ] as { value: string; label: string }[],
    source: 'Preview data',
    useShared: 'Use the shared sample',
    asLabel: (name: string) => `Sample data · previewing as ${name}`,
    audited: 'Every use is recorded in the audit log.',
    userUnavailable: (name: string) =>
        `The preview as ${name} is not available right now, so the shared sample is shown.`,
    readFailed: "We couldn't read the fetched result. Try again.",
    mismatch:
        "This result is for a different data source or endpoint than the draft's, so the shared sample is shown. Fetch again.",
} as const;

// The User dashboard page (Story 4.4; UX-DR-50, 86, 149, 236, 237). The page title is the shell's "Overview"; the states' messages are
// `msg:dashboard-empty`, `msg:dashboard-load-failed`, `msg:block-unpublished` and `msg:block-access-removed`.
export const dashboardLabels = {
    addBlock: '+ Add block',
    editLayout: '✎ Edit layout',
    switcher: 'Dashboard',
    dateRange: 'Date range',
    retry: 'Retry',
    remove: 'Remove',
    loading: 'Loading your dashboard',
    grid: 'Blocks',
    unavailableBlock: 'Unavailable block',
    loadingBlock: (title: string): string => `Loading ${title}`,
    bodyRegion: (title: string): string => `${title} content`,
    removeBlock: (title: string): string => `Remove ${title}`,
    minimized: 'Minimized',
    live: 'Live',
    laterStory: 'Available in a later update',
    refresh: (title: string): string => `Refresh ${title}`,
    minimize: (title: string): string => `Minimize ${title}`,
    expand: (title: string): string => `Expand ${title}`,
    moreActions: (title: string): string => `More actions for ${title}`,
    refreshing: 'Refreshing',
    menuRefresh: 'Refresh',
    menuAbout: 'About this block',
    menuRemove: 'Remove from dashboard',
    about: {
        title: 'About this block',
        description: 'Description',
        source: 'Data source',
        interval: 'Refresh interval',
        asOf: 'Data as of',
        version: 'Version',
        period: 'Period',
        none: 'Not available',
        technical: 'Technical details',
        close: 'Close',
        live: 'Live',
        every: (n: number, unit: string): string =>
            `Every ${n} ${unit}${n === 1 ? '' : 's'}`,
        periods: {
            follow_dashboard: 'Follows the dashboard date range',
            own_selector: 'Has its own period selector',
            not_date_filtered: 'Not filtered by date',
        },
    },
    footerLinkExternal: (label: string): string =>
        `${label} (opens in a new tab)`,
} as const;
