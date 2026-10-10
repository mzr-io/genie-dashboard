import { ROLE_TONES } from '@/lib/blockMapping';
import type { PickerField } from '@/lib/blockMapping';
import type { SampleNode } from '@/lib/blockSample';
import type { InjectionKey } from 'vue';

// The model of the searchable field tree (Story 3.11d): the sample's flat pre-order node list becomes the rows an APG tree shows. Pure functions,
// so the component only draws what they return. The server owns every rule (what fits a slot comes from the slot-fields listing).

// One row of the tree, nested the way the `group` markup is.
export type TreeModel = {
    node: SampleNode;
    // `aria-level`, `aria-setsize`, `aria-posinset`.
    level: number;
    setSize: number;
    pos: number;
    // The node has children in the sample (a branch), shown or not.
    branch: boolean;
    // Shown children (only when the branch is expanded).
    children: TreeModel[];
};

export type TreeView = {
    roots: TreeModel[];
    // Every shown row in document order, for the arrow keys.
    flat: TreeModel[];
};

// The root `$` of an object is not a field of its own: its children are the first level.
function hiddenRoot(nodes: SampleNode[]): number | null {
    const root = nodes.find((node) => node.parent === null);

    return root !== undefined && nodes.some((node) => node.parent === root.id)
        ? root.id
        : null;
}

function childrenOf(nodes: SampleNode[]): Map<number | null, SampleNode[]> {
    const map = new Map<number | null, SampleNode[]>();
    const hidden = hiddenRoot(nodes);

    for (const node of nodes) {
        const parent = node.parent === hidden ? null : node.parent;

        if (node.id === hidden) {
            continue;
        }

        map.set(parent, [...(map.get(parent) ?? []), node]);
    }

    return map;
}

// What a search keeps: the nodes that match, their ancestors (which open by themselves) and, for a branch that matches, everything below it.
export type TreeSearch = {
    keep: Set<number>;
    open: Set<number>;
    // The fields (leaves) that stay.
    fields: number;
};

function matches(node: SampleNode, needle: string): boolean {
    return [
        node.display,
        node.key,
        node.type,
        typeWord(node.type),
        node.example,
    ]
        .filter((part): part is string => typeof part === 'string')
        .some((part) => part.toLowerCase().includes(needle));
}

export function searchTree(nodes: SampleNode[], query: string): TreeSearch {
    const needle = query.trim().toLowerCase();
    const kids = childrenOf(nodes);
    const byId = new Map(nodes.map((node) => [node.id, node]));
    const keep = new Set<number>();
    const open = new Set<number>();
    const hidden = hiddenRoot(nodes);

    const addBelow = (id: number): void => {
        for (const child of kids.get(id) ?? []) {
            keep.add(child.id);
            addBelow(child.id);
        }
    };

    for (const node of nodes) {
        if (!byId.has(node.id) || !matches(node, needle)) {
            continue;
        }

        keep.add(node.id);
        addBelow(node.id);

        let parent = node.parent;

        while (parent !== null && parent !== hidden) {
            keep.add(parent);
            open.add(parent);
            parent = byId.get(parent)?.parent ?? null;
        }
    }

    const fields = [...keep].filter(
        (id) => (kids.get(id) ?? []).length === 0 && byId.has(id),
    ).length;

    return { keep, open, fields };
}

// The rows to show: every node (or the ones a search kept) whose ancestors are all expanded.
export function buildTree(
    nodes: SampleNode[],
    expanded: ReadonlySet<number>,
    keep: ReadonlySet<number> | null = null,
): TreeView {
    const kids = childrenOf(nodes);
    const flat: TreeModel[] = [];

    const make = (siblings: SampleNode[], level: number): TreeModel[] => {
        const shown = siblings.filter(
            (node) => keep === null || keep.has(node.id),
        );

        return shown.map((node, index) => {
            const below = kids.get(node.id) ?? [];
            const model: TreeModel = {
                node,
                level,
                setSize: shown.length,
                pos: index + 1,
                branch: below.length > 0,
                children: [],
            };

            flat.push(model);

            if (below.length > 0 && expanded.has(node.id)) {
                model.children = make(below, level + 1);
            }

            return model;
        });
    };

    return { roots: make(kids.get(null) ?? [], 1), flat };
}

// Every branch, for "Expand all".
export function branchIds(nodes: SampleNode[]): number[] {
    const kids = childrenOf(nodes);

    return nodes
        .filter((node) => (kids.get(node.id) ?? []).length > 0)
        .map((node) => node.id);
}

// The nodes from the first level down to this one, for the breadcrumb.
export function trail(nodes: SampleNode[], id: number): SampleNode[] {
    const byId = new Map(nodes.map((node) => [node.id, node]));
    const hidden = hiddenRoot(nodes);
    const out: SampleNode[] = [];
    let at: SampleNode | undefined = byId.get(id);

    while (at !== undefined && at.id !== hidden) {
        out.unshift(at);
        at = at.parent === null ? undefined : byId.get(at.parent);
    }

    return out;
}

// What a row says as its name: the key, or `[]` for an array's items.
export function rowText(node: SampleNode): string {
    if (node.key === '*') {
        return '[]';
    }

    return node.key ?? node.display;
}

// Type-ahead: the next shown row (after the current one, wrapping round) whose text starts with what was typed.
export function typeAhead(
    rows: TreeModel[],
    currentId: number | null,
    typed: string,
): TreeModel | null {
    const needle = typed.toLowerCase();

    if (needle === '' || rows.length === 0) {
        return null;
    }

    const from = rows.findIndex((row) => row.node.id === currentId);
    // One letter starts after the current row (pressing it again cycles); a longer word may stay on the current row.
    const start = from === -1 ? 0 : needle.length === 1 ? from + 1 : from;

    for (let step = 0; step < rows.length; step += 1) {
        const row = rows[(start + step) % rows.length];

        if (rowText(row.node).toLowerCase().startsWith(needle)) {
            return row;
        }
    }

    return null;
}

// ---- Type chips -------------------------------------------------------------------------------------------------------

// The type as text: the sample's `string` reads "text", `integer` and `decimal` read "number".
export function typeWord(type: string): string {
    return type
        .split(' | ')
        .map((part) =>
            part === 'string'
                ? 'text'
                : part === 'integer' || part === 'decimal'
                  ? 'number'
                  : part,
        )
        .filter((part, at, all) => all.indexOf(part) === at)
        .join(' | ');
}

const NEUTRAL = 'bg-surface-muted text-text-secondary border-border-default';

// The chip's colours by role family (number blue Measure, text grey Label, date violet Time, array orange Filter, object neutral). The type is
// always also printed, so the colour never carries it alone.
export function typeTone(type: string): string {
    switch (typeWord(type)) {
        case 'number':
            return ROLE_TONES.measure;
        case 'text':
            return ROLE_TONES.label;
        case 'date':
            return ROLE_TONES.time;
        case 'array':
            return ROLE_TONES.filter;
        default:
            return NEUTRAL;
    }
}

// ---- Compatibility join -----------------------------------------------------------------------------------------------

export type Fit = {
    // True when the slot takes the field, false when it does not, null while it is not known (loading or failed).
    ok: boolean | null;
    reason: string | null;
    // The field's effective type, when the listing knows it.
    type: string | null;
};

// Joins a node to the slot's field listing. A node the listing does not offer cannot feed the slot.
export function fitOf(
    node: SampleNode,
    listing: ReadonlyMap<string, PickerField> | null,
    notAField: string,
): Fit {
    if (listing === null) {
        return { ok: null, reason: null, type: null };
    }

    const entry = listing.get(node.path);

    if (entry === undefined) {
        return { ok: false, reason: notAField, type: null };
    }

    return {
        ok: entry.compatible,
        reason: entry.compatible ? null : (entry.reason ?? notAField),
        type: entry.effective_type ?? entry.type ?? null,
    };
}

// The listing keyed by stored path. The slot listing may name a field relative to the record path (`$.revenue`) while the sample's nodes are
// absolute (`$.data.monthly[*].revenue`): when no listed path lies under the record path, each one is also keyed under it, so both forms join.
export function listingOf(
    fields: PickerField[],
    recordPath: string | null = null,
): Map<string, PickerField> {
    const entries = fields.filter(
        (field): field is PickerField & { path: string } => field.path !== null,
    );
    const map = new Map(entries.map((field) => [field.path, field]));

    if (recordPath !== null && recordPath.startsWith('$')) {
        const base = recordPath.replace(/\[\*\]$/, '');
        const absolute = entries.some((field) =>
            field.path.startsWith(`${base}[*]`),
        );

        if (!absolute) {
            for (const field of entries) {
                const derived = `${recordPath}${field.path.slice(1)}`;

                if (field.path.startsWith('$.') && !map.has(derived)) {
                    map.set(derived, field);
                }
            }
        }
    }

    return map;
}

// The slot the tree offers first: the first required slot with nothing mapped, else the headline, else the first required one.
export function defaultSlot(
    slots: { slot: string; required: boolean; state: string }[],
): string | null {
    return (
        slots.find((s) => s.required && s.state === 'required_missing')?.slot ??
        slots.find((s) => s.slot === 'headline_value')?.slot ??
        slots.find((s) => s.required)?.slot ??
        slots[0]?.slot ??
        null
    );
}

// What every row of the tree reads from the tree itself (provided by `FieldTree.vue`, injected by `FieldTreeItem.vue`).
export type TreeContext = {
    isExpanded: (id: number) => boolean;
    // The row that holds the roving tab stop, and the leaf last looked at.
    tabbableId: () => number | null;
    selectedId: () => number | null;
    fit: (node: SampleNode) => Fit;
    // Use buttons are drawn (not in a read-only view) and can act now (no write in flight).
    canUse: () => boolean;
    busy: () => boolean;
    slotLabel: () => string;
    activate: (model: TreeModel) => void;
    focused: (model: TreeModel) => void;
    use: (model: TreeModel) => void;
    reasonId: (id: number) => string;
    elementId: (id: number) => string;
};

export const TREE_CONTEXT: InjectionKey<TreeContext> = Symbol('field-tree');
