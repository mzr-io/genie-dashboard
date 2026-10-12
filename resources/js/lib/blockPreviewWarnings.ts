import type { ChecklistSlot } from '@/lib/blockMapping';
import type { PreviewResult } from '@/lib/blockPreview';
import {
    blockPreviewLabels,
    blockPreviewStepLabels as labels,
} from '@/locales/labels';

// The warnings Step 4 (Story 3.16) lists, derived only from what the mapping checklist and the preview already say. Each has an `id`: a
// fingerprint of the warning and of what it is about, so an acknowledgement stored in `draft_ui_state` belongs to that exact warning and goes
// when the underlying mapping or sample value changes. Nothing here writes or asks the server.

export type PreviewWarningKind =
    | 'cleared_optional'
    | 'null_value'
    | 'unavailable_value'
    | 'stale_mapping';

export type PreviewWarning = {
    id: string;
    kind: PreviewWarningKind;
    slot: string;
    text: string;
};

const MAPPED = [
    'auto_high',
    'auto_medium',
    'auto_low',
    'confirmed',
    'overridden',
];

// A small stable hash (the server keeps each acknowledgement to 100 characters, and a path can be longer than that).
function hash(text: string): string {
    let h = 5381;

    for (let i = 0; i < text.length; i += 1) {
        h = ((h << 5) + h + text.charCodeAt(i)) | 0;
    }

    return (h >>> 0).toString(36);
}

export const WARNING_PREFIX = 'w:';

function make(
    kind: PreviewWarningKind,
    slot: string,
    parts: (string | null | undefined)[],
    text: string,
): PreviewWarning {
    return {
        id: `${WARNING_PREFIX}${kind}:${slot}:${hash(JSON.stringify(parts.map((p) => p ?? '')))}`,
        kind,
        slot,
        text,
    };
}

export function previewWarnings(
    slots: ChecklistSlot[],
    result: PreviewResult | null,
): PreviewWarning[] {
    const out: PreviewWarning[] = [];

    for (const slot of slots) {
        const mapped = MAPPED.includes(slot.state) && !!slot.path;

        // An optional slot left empty although a field was offered or mapped before.
        if (
            !slot.required &&
            slot.state === 'optional_empty' &&
            (slot.suggestion ||
                slot.path ||
                slot.reason === 'Cleared by an admin.')
        ) {
            out.push(
                make(
                    'cleared_optional',
                    slot.slot,
                    [slot.state, slot.path, slot.suggestion?.path, slot.reason],
                    labels.clearedOptional(slot.label),
                ),
            );
        }

        if (!mapped || result === null) {
            continue;
        }

        const server = result.slots?.[slot.slot];
        const reason = server?.unavailable ?? result.unavailable?.[slot.slot];
        const field = slot.display ?? slot.path ?? slot.slot;

        if (reason) {
            out.push(
                make(
                    'unavailable_value',
                    slot.slot,
                    [slot.path, reason],
                    labels.unavailableValue(
                        slot.label,
                        field,
                        blockPreviewLabels.unavailableReasons[reason] ?? reason,
                    ),
                ),
            );
        } else if (!slot.required && server && server.value === null) {
            out.push(
                make(
                    'null_value',
                    slot.slot,
                    [slot.path, String(result.sample_revision)],
                    labels.nullValue(slot.label, field),
                ),
            );
        }
    }

    if (result?.plan_stale) {
        out.push(
            make(
                'stale_mapping',
                '',
                [String(result.sample_revision)],
                labels.staleMapping,
            ),
        );
    }

    return out;
}
