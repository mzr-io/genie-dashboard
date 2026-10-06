// The page-level form-draft hook (Story 1.15). A form that wants its unsaved fields back after the
// session idled out registers itself; forms that do not register are never restored (data source form,
// template editor, settings). The Create-block wizard keeps its own autosave and does not use this.
//
//   const stop = registerFormDraft({ id: 'profile', snapshot: () => formValues(form), restore: (s) => ... });
//
// On expiry every registered snapshot goes to sessionStorage under one fixed key (no user or Workspace in
// it) as `{owner, savedAt, values}`; `owner` is a non-identifying hash of the signed-in user. After sign-in
// the page registers again and `restore` is called once, only when the owner matches (a draft of another
// person is discarded). The draft is deleted only after `restore` succeeds. Secrets are never stored:
// `formValues` skips password inputs, fields with `data-secret` and secret-named fields, and a snapshot is
// scrubbed of any key with a secret word in it before it is written.
import { usePage } from '@inertiajs/vue3';

export type FormDraft<T = unknown> = {
    id: string;
    snapshot: () => T;
    restore: (snapshot: T) => void;
};

export const DRAFT_STORAGE_KEY = 'dashflow:form-drafts';

// A name is secret when one of its words (split on punctuation, case changes and digits) is one of these.
const SECRET_WORDS = new Set([
    'password',
    'passwd',
    'passphrase',
    'pass',
    'secret',
    'token',
    'otp',
    'pin',
    'cvv',
    'cvc',
    'card',
    'authorization',
    'credential',
    'credentials',
    'apikey',
]);

export function isSecretName(name: string): boolean {
    const words = name
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .toLowerCase()
        .split(/[^a-z0-9]+/)
        .filter(Boolean);

    return (
        words.some((word) => SECRET_WORDS.has(word)) ||
        words.some((word, i) => word === 'api' && words[i + 1] === 'key')
    );
}

type StoredDraft = { owner: string | null; savedAt: number; values: unknown };
type Stored = Record<string, StoredDraft>;

const registry = new Map<string, FormDraft>();

// A short hash of the signed-in user's id: it tells two people apart without naming either.
function hashOwner(id: unknown): string | null {
    if (id === null || id === undefined || id === '') {
        return null;
    }

    let hash = 0x811c9dc5;

    for (const char of `dashflow:${String(id as string | number)}`) {
        hash ^= char.charCodeAt(0);
        hash = Math.imul(hash, 0x01000193) >>> 0;
    }

    return hash.toString(16);
}

let ownerResolver: () => string | null = () => {
    try {
        const user = (
            usePage().props as { auth?: { user?: { id?: unknown } | null } }
        ).auth?.user;

        return hashOwner(user?.id);
    } catch {
        return null;
    }
};

// For tests and pages whose user is not in the shared props.
export function setDraftOwnerResolver(resolver: () => string | null): void {
    ownerResolver = resolver;
}

export { hashOwner };

function storage(): Storage | null {
    try {
        return typeof sessionStorage === 'undefined' ? null : sessionStorage;
    } catch {
        return null;
    }
}

function readStored(): Stored {
    try {
        const raw = storage()?.getItem(DRAFT_STORAGE_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : {};

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
            ? (parsed as Stored)
            : {};
    } catch {
        return {};
    }
}

function writeStored(value: Stored): void {
    try {
        if (Object.keys(value).length === 0) {
            storage()?.removeItem(DRAFT_STORAGE_KEY);
        } else {
            storage()?.setItem(DRAFT_STORAGE_KEY, JSON.stringify(value));
        }
    } catch {
        // Storage can be full or blocked: the draft is simply not kept.
    }
}

// Drops every key that names a secret, at any depth.
export function scrubSecrets(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(scrubSecrets);
    }

    if (value && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value as Record<string, unknown>)
                .filter(([name]) => !isSecretName(name))
                .map(([name, inner]) => [name, scrubSecrets(inner)]),
        );
    }

    return value;
}

function isSecretField(field: Element): boolean {
    return (
        (field instanceof HTMLInputElement && field.type === 'password') ||
        field.hasAttribute('data-secret') ||
        field.closest('[data-secret]') !== null ||
        isSecretName(field.getAttribute('name') ?? '')
    );
}

// The named fields of a form as plain values, without any secret-typed field. Several checkboxes of one
// name give the array of the checked ones; a `<select multiple>` gives the array of selected options.
export function formValues(
    root: HTMLElement,
): Record<string, string | string[]> {
    const values: Record<string, string | string[]> = {};
    const boxes = new Map<string, HTMLInputElement[]>();

    root.querySelectorAll('input, textarea, select').forEach((field) => {
        const name = field.getAttribute('name');

        if (!name || isSecretField(field)) {
            return;
        }

        if (field instanceof HTMLSelectElement && field.multiple) {
            values[name] = [...field.selectedOptions].map((o) => o.value);

            return;
        }

        if (field instanceof HTMLInputElement) {
            if (['file', 'hidden', 'submit', 'button'].includes(field.type)) {
                return;
            }

            if (field.type === 'checkbox') {
                boxes.set(name, [...(boxes.get(name) ?? []), field]);

                return;
            }

            if (field.type === 'radio' && !field.checked) {
                return;
            }
        }

        values[name] = (field as HTMLInputElement).value;
    });

    boxes.forEach((group, name) => {
        const checked = group
            .filter((box) => box.checked)
            .map((box) => box.value);

        if (group.length > 1) {
            values[name] = checked;
        } else if (checked.length > 0) {
            values[name] = checked[0];
        }
    });

    return values;
}

// Registers a form; returns the function that unregisters it. A draft saved at the last expiry is handed
// to `restore` once if it belongs to the signed-in person; one that does not is discarded.
export function registerFormDraft<T>(draft: FormDraft<T>): () => void {
    registry.set(draft.id, draft as FormDraft);

    const stored = readStored();
    const entry = Object.hasOwn(stored, draft.id)
        ? stored[draft.id]
        : undefined;
    const owner = ownerResolver();

    // Nobody is signed in on this page: leave the draft for the page that follows sign-in.
    if (entry && owner !== null) {
        if (entry.owner !== owner) {
            delete stored[draft.id];
            writeStored(stored);
        } else {
            try {
                draft.restore(entry.values as T);
                delete stored[draft.id];
                writeStored(stored);
            } catch {
                // A broken restore must not break the page; the draft stays for the next try.
            }
        }
    }

    return () => {
        if (registry.get(draft.id) === draft) {
            registry.delete(draft.id);
        }
    };
}

// Called on expiry: stores the scrubbed snapshot of every registered form, next to drafts that were never
// restored. Nothing captured means nothing written.
export function saveFormDrafts(): void {
    const captured: Stored = {};
    const owner = ownerResolver();

    registry.forEach((draft, id) => {
        try {
            const snapshot = draft.snapshot();

            if (snapshot !== null && snapshot !== undefined) {
                captured[id] = {
                    owner,
                    savedAt: Date.now(),
                    values: scrubSecrets(snapshot),
                };
            }
        } catch {
            // One failing form must not lose the others.
        }
    });

    if (Object.keys(captured).length === 0) {
        return;
    }

    writeStored({ ...readStored(), ...captured });
}

// A deliberate sign-out leaves nothing behind.
export function clearFormDrafts(): void {
    writeStored({});
}
