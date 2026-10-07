export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    locale?: string | null;
    timezone?: string | null;
    keyboard_shortcuts?: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type ShellArea = 'user' | 'admin';

// One navigation target as the server shares it. The client owns the title and the icon (it maps `key`).
// `allowed` is false when an Admin lacks `permission`: the item is shown disabled, never hidden.
export type ShellItem = {
    key: string;
    href: string;
    permission: string | null;
    allowed: boolean;
};

export type ShellWorkspace = {
    id: string;
    name: string;
    label: string | null;
    role: string;
};

// The two-area shell's navigation model (Story 1.16), shared with every page of a signed-in person.
export type Shell = {
    area: ShellArea;
    workspace: { id: string; name: string; label: string | null } | null;
    role: string | null;
    // The Workspaces the person may switch to (usable memberships only), with their role in each (Story 1.17).
    workspaces: ShellWorkspace[];
    switch_href: string;
    items: ShellItem[];
    help_href: string;
    profile_href: string;
    sign_out_href: string;
};
