// The shortcut registry (Story 1.18; UX-DR-269). Features register their keyboard shortcuts here; one
// listener (`installShortcuts`) runs them. The registry owns the Keyboard shortcuts switch of Profile &
// settings:
//
//   - A single-key shortcut (no Ctrl, Cmd or Alt) fires only while the switch is On, only while its
//     component has focus (`scope`), and never while focus is in a text field.
//   - A modifier shortcut (Ctrl or Cmd, or Alt) works whatever the switch says. So do widget keys (arrows,
//     Enter, Space, Esc, Tab): those belong to their widgets and are never registered here.
//
//   const stop = registerShortcut({ id: 'drawer-add', key: 'a', scope: () => drawerRow.value, run: add });
//   onBeforeUnmount(stop);

export type Modifier = 'mod' | 'alt' | 'shift';

export type Shortcut = {
    id: string;
    key: string;
    // `mod` is Ctrl or Cmd. With none listed the shortcut is a single-key shortcut.
    modifiers?: Modifier[];
    // The component the shortcut belongs to; a single-key shortcut needs focus inside it.
    scope?: () => HTMLElement | null | undefined;
    run: (event: KeyboardEvent) => void;
};

const registry = new Map<string, Shortcut>();
let singleKeyEnabled = true;

export function registerShortcut(shortcut: Shortcut): () => void {
    if (registry.has(shortcut.id)) {
        // The later one replaces the earlier: a silent clash would make a shortcut vanish.
        console.warn(`Shortcut id "${shortcut.id}" is registered twice.`);
    }

    registry.set(shortcut.id, shortcut);

    return () => {
        if (registry.get(shortcut.id) === shortcut) {
            registry.delete(shortcut.id);
        }
    };
}

export function clearShortcuts(): void {
    registry.clear();
}

// The saved Keyboard shortcuts switch of the signed-in person (default On).
export function setSingleKeyShortcuts(enabled: boolean): void {
    singleKeyEnabled = enabled;
}

export function singleKeyShortcutsEnabled(): boolean {
    return singleKeyEnabled;
}

export function isSingleKey(shortcut: Shortcut): boolean {
    const modifiers = shortcut.modifiers ?? [];

    return !modifiers.includes('mod') && !modifiers.includes('alt');
}

const TEXT_INPUT_TYPES = new Set([
    'text',
    'search',
    'email',
    'url',
    'tel',
    'password',
    'number',
    'date',
    'datetime-local',
    'month',
    'time',
    'week',
]);

export function inTextField(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    if (target.isContentEditable) {
        return true;
    }

    if (target instanceof HTMLTextAreaElement) {
        return true;
    }

    if (target instanceof HTMLSelectElement) {
        return true;
    }

    // A custom text control (a combobox or search built from divs) counts as a text field too.
    if (
        target.closest(
            '[role="textbox"], [role="combobox"], [role="searchbox"]',
        )
    ) {
        return true;
    }

    return (
        target instanceof HTMLInputElement &&
        TEXT_INPUT_TYPES.has((target.type || 'text').toLowerCase())
    );
}

function matches(shortcut: Shortcut, event: KeyboardEvent): boolean {
    const modifiers = shortcut.modifiers ?? [];

    if (event.key.toLowerCase() !== shortcut.key.toLowerCase()) {
        return false;
    }

    if ((event.ctrlKey || event.metaKey) !== modifiers.includes('mod')) {
        return false;
    }

    if (event.altKey !== modifiers.includes('alt')) {
        return false;
    }

    // Shift only counts when the shortcut names it ("?" and "/" need Shift on some layouts).
    return !modifiers.includes('shift') || event.shiftKey;
}

function allowed(shortcut: Shortcut, event: KeyboardEvent): boolean {
    if (!isSingleKey(shortcut)) {
        return true;
    }

    if (!singleKeyEnabled || inTextField(event.target)) {
        return false;
    }

    if (shortcut.scope) {
        const scope = shortcut.scope();
        const focused = document.activeElement;

        return Boolean(scope && focused && scope.contains(focused));
    }

    return true;
}

// Runs the first registered shortcut the event matches and is allowed to trigger; true when one ran.
export function dispatchShortcut(event: KeyboardEvent): boolean {
    // Some browsers fire a keydown without a key (autofill); there is nothing to match.
    if (typeof event.key !== 'string') {
        return false;
    }

    if (event.defaultPrevented || event.isComposing || event.repeat) {
        return false;
    }

    for (const shortcut of registry.values()) {
        if (matches(shortcut, event) && allowed(shortcut, event)) {
            shortcut.run(event);

            return true;
        }
    }

    return false;
}

// Starts the one keydown listener; returns the function that removes it.
export function installShortcuts(target: Document = document): () => void {
    const listener = (event: KeyboardEvent) => void dispatchShortcut(event);

    target.addEventListener('keydown', listener);

    return () => target.removeEventListener('keydown', listener);
}
