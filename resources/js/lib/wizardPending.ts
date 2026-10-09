import { currentOwner, scrubSecrets } from '@/lib/formDrafts';
import type { SaveBody } from '@/lib/blockWizard';

// The Create block wizard's unsaved changes across a sign-in (Story 3.5). When a save is refused because the session ended, the pending
// delta is kept in sessionStorage and replayed after sign-in. It holds only what a Draft save holds (name, type, category, config, wizard
// state) with every secret-named key removed, and a hash of the signed-in person: another person's entry is never replayed, and unsaved
// secret fields are never put here at all (the wizard has none, and `scrubSecrets` drops any secret-named key regardless).
export const PENDING_KEY = 'dashflow:block-wizard-pending';

export type PendingDelta = Omit<SaveBody, 'expected_revision' | 'lock_epoch'>;

export type PendingChanges = {
    owner: string | null;
    draftId: string;
    // The revision the changes were made on: the replay is a compare-and-set against it.
    baseRevision: number;
    lockEpoch: number;
    // The step the Admin was looking at, so the wizard reopens there.
    viewStep: number;
    savedAt: number;
    // True when the session-expiry flush was acknowledged (it ran before the session ended and the work was kept): only then does the
    // sign-in say `session-expired`, since only then is "your draft was saved" true.
    acknowledged: boolean;
    delta: PendingDelta;
};

function storage(): Storage | null {
    try {
        return typeof sessionStorage === 'undefined' ? null : sessionStorage;
    } catch {
        return null;
    }
}

// Keeps the changes; returns whether the store took them.
export function stashPending(
    entry: Omit<PendingChanges, 'owner' | 'savedAt'>,
): boolean {
    const store = storage();

    if (!store) {
        return false;
    }

    try {
        const value: PendingChanges = {
            ...entry,
            owner: currentOwner(),
            savedAt: Date.now(),
            delta: scrubSecrets(entry.delta) as PendingDelta,
        };

        store.setItem(PENDING_KEY, JSON.stringify(value));

        return true;
    } catch {
        return false;
    }
}

// The kept changes for this Draft that belong to the signed-in person. Another person's, or another Draft's, are left alone (the owner's
// next sign-in replays them) except that another person's are dropped: they must not outlive the sign-in of someone else.
export function readPending(draftId: string): PendingChanges | null {
    const store = storage();

    try {
        const raw = store?.getItem(PENDING_KEY);

        if (!raw) {
            return null;
        }

        const entry = JSON.parse(raw) as Partial<PendingChanges> | null;
        const owner = currentOwner();

        if (!entry || typeof entry !== 'object' || owner === null) {
            return null;
        }

        if (entry.owner !== owner) {
            store?.removeItem(PENDING_KEY);

            return null;
        }

        return entry.draftId === draftId &&
            typeof entry.baseRevision === 'number' &&
            entry.delta &&
            typeof entry.delta === 'object'
            ? (entry as PendingChanges)
            : null;
    } catch {
        return null;
    }
}

export function clearPending(): void {
    try {
        storage()?.removeItem(PENDING_KEY);
    } catch {
        // Nothing to clear.
    }
}
