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
