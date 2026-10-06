export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
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

// The two-area shell's navigation model (Story 1.16), shared with every page of a signed-in person.
export type Shell = {
    area: ShellArea;
    workspace: { id: string; name: string } | null;
    role: string | null;
    items: ShellItem[];
    help_href: string;
    profile_href: string;
    sign_out_href: string;
};
