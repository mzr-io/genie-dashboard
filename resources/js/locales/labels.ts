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

// Labels of the placeholder pages that Stories 1.16, 1.18 and 1.19 replace.
export const placeholderLabels = {
    adminOverview: 'Admin overview',
    help: 'Help & support',
} as const;
