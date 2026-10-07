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
    // Placeholders until Stories 2.18 (health) and 2.14 (last call) and Epic 3 (blocks) fill them.
    checking: 'Checking…',
    noCall: '—',
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
    nameRequired: 'Enter a name.',
    baseUrlRequired: 'Enter the base URL.',
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
        'auth-type-unavailable':
            'This authentication type is not available yet.',
        'auth-type-invalid': 'Choose an authentication type.',
        'api-key-name-required': 'Enter the name the API key is sent under.',
        'api-key-name-invalid':
            "The name uses letters, digits and the characters ! # $ % & ' * + - . ^ _ ` | ~ only.",
        'api-key-name-reserved':
            'This name is reserved. Choose another name for the API key.',
        'api-key-name-duplicate': 'A default header already uses this name.',
        'api-key-placement-invalid': 'Choose header or query string.',
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
    } as Record<string, string>,
    apiKeyName: 'API key name',
    apiKeyNameHelper:
        'The header or query parameter the key is sent under, such as X-Api-Key.',
    apiKeyPlacement: 'Send the key in',
    placementHeader: 'A header (recommended)',
    placementQuery: 'The query string',
    queryWarning:
        'A key in the query string can end up in the API’s logs and in proxies. Use a header if the API allows it.',
    apiKey: 'API key',
    bearerToken: 'Token',
    basicUsername: 'User name',
    basicPassword: 'Password',
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
} as const;

// Labels of the session-expiry warning (Story 1.15). Its message (`session-warning`) and the toast after
// signing back in (`session-expired`) come from the catalogue.
export const sessionLabels = {
    title: 'Your session is about to end',
    stay: 'Stay signed in',
    signOut: 'Sign out',
    extendFailed: "We couldn't keep you signed in. Try again.",
} as const;
