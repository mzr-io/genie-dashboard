// Control labels that are not canonical messages (Story 1.7). They are local to the components
// that render them, so the catalogue keeps exactly the 89 canonical keys.
export const technicalDetailsLabels = {
    title: 'Technical details',
    status: 'HTTP status',
    path: 'Field path',
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

// Labels of the session-expiry warning (Story 1.15). Its message (`session-warning`) and the toast after
// signing back in (`session-expired`) come from the catalogue.
export const sessionLabels = {
    title: 'Your session is about to end',
    stay: 'Stay signed in',
    signOut: 'Sign out',
    extendFailed: "We couldn't keep you signed in. Try again.",
} as const;
