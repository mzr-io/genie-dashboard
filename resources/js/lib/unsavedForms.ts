// Pages report unsaved form work here (Story 1.17), so a Workspace switch can ask first (`unsaved-changes`).
//
//   const stop = registerUnsavedForm({ id: 'profile', isDirty: () => form.isDirty, save: async () => ... });
//   onBeforeUnmount(stop);
//
// `isDirty` is read when the question is asked, never cached. `save` is optional: it returns true when the
// work was saved (the switch may go on) and false when it was not (the person stays where they are).
export type UnsavedForm = {
    id: string;
    isDirty: () => boolean;
    save?: () => boolean | Promise<boolean>;
};

const registry = new Map<string, UnsavedForm>();

// Returns the function that unregisters the form.
export function registerUnsavedForm(form: UnsavedForm): () => void {
    registry.set(form.id, form);

    return () => {
        if (registry.get(form.id) === form) {
            registry.delete(form.id);
        }
    };
}

export function hasUnsavedWork(): boolean {
    return [...registry.values()].some((form) => {
        try {
            return form.isDirty();
        } catch {
            // A form that cannot say is treated as dirty: asking is safer than losing work.
            return true;
        }
    });
}

// Saves every dirty form. True only when every one of them saved.
export async function saveUnsavedForms(): Promise<boolean> {
    for (const form of registry.values()) {
        let dirty = true;

        try {
            dirty = form.isDirty();
        } catch {
            // Treated as dirty.
        }

        if (!dirty) {
            continue;
        }

        try {
            if (!form.save || !(await form.save())) {
                return false;
            }
        } catch {
            return false;
        }
    }

    return true;
}

// Discard changes: nothing is kept; the forms are simply left behind by the switch.
export function clearUnsavedForms(): void {
    registry.clear();
}
