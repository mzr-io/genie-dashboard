import {
    Boxes,
    CircleHelp,
    CircleCheckBig,
    Database,
    FilePen,
    House,
    LayoutDashboard,
    LayoutGrid,
    LayoutTemplate,
    ScrollText,
    Settings,
    SquarePlus,
    Tags,
    UserRound,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { shellPages } from '@/locales/labels';
import type { ShellPageKey } from '@/locales/labels';
import type { ShellItem } from '@/types/auth';

// The icon of every navigation target, by item key (16px in the sidebar, 20px in the icon rail).
export const shellIcons: Record<ShellPageKey, LucideIcon> = {
    overview: House,
    'my-dashboards': LayoutGrid,
    templates: LayoutTemplate,
    profile: UserRound,
    help: CircleHelp,
    'admin-overview': LayoutDashboard,
    'block-management': Boxes,
    'create-block': SquarePlus,
    'draft-blocks': FilePen,
    'published-blocks': CircleCheckBig,
    'block-categories': Tags,
    'dashboard-templates': LayoutTemplate,
    'data-sources': Database,
    'user-configuration': Users,
    'system-settings': Settings,
    'audit-log': ScrollText,
};

export type ShellNavItem = ShellItem & {
    title: string;
    icon: LucideIcon;
};

export function isPageKey(key: string): key is ShellPageKey {
    return Object.hasOwn(shellPages, key);
}

// Items the client knows how to label; an unknown key from the server is dropped, not shown blank.
export function navItems(items: ShellItem[]): ShellNavItem[] {
    return items
        .filter((item) => isPageKey(item.key))
        .map((item) => ({
            ...item,
            title: shellPages[item.key as ShellPageKey].title,
            icon: shellIcons[item.key as ShellPageKey],
        }));
}

// Pages that belong to an item without sharing its path (any /settings page sits under Profile & settings).
const OWNED_PREFIXES: Partial<Record<ShellPageKey, string>> = {
    profile: '/settings',
};

// The item the current path belongs to: an exact match, else the item with the longest path that contains it.
export function currentItem<T extends ShellItem>(
    items: T[],
    path: string,
): T | null {
    const exact = items.find((item) => item.href === path);

    if (exact) {
        return exact;
    }

    let best: T | null = null;
    let bestLength = 0;

    for (const item of items) {
        const prefix = OWNED_PREFIXES[item.key as ShellPageKey] ?? item.href;

        if (
            path.startsWith(`${prefix}/`) &&
            prefix.length > bestLength &&
            // The Admin overview path is a prefix of every Admin page: it owns only itself.
            item.key !== 'admin-overview'
        ) {
            best = item;
            bestLength = prefix.length;
        }
    }

    return best;
}
