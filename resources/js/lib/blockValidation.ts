import { WizardError, wizardCall } from '@/lib/blockWizard';
import type { SampleClaim } from '@/lib/blockSample';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';

// Validate against the real API (Story 3.18, wizard Step 5). The server fetches the Draft's own Endpoint live, runs the Draft's plan over the answer
// and stores a report bound to the Draft's revision. This module holds only what the browser may know: the status, the revision it is bound to and
// the mismatching paths and types. No response value is ever here.

export type ValidationStatus = 'passed' | 'failed' | 'unreachable' | 'stale';

export type ValidationMismatch = {
    // The stored path the plan reads, or a config key for a strict-schema fault; null when there is none.
    path: string | null;
    slot: string | null;
    // `missing`, `wrong_type`, `not_single_value` (the live response), or a strict-schema fault.
    type: string;
    // The slot's value type, when the fault is about a slot.
    expected: string | null;
};

export type ValidationReport = {
    id: string;
    // The effective status: a stored result for an earlier revision reads `stale`.
    status: ValidationStatus;
    stored_status: ValidationStatus;
    reason: string | null;
    subject_revision: number;
    draft_revision: number;
    mismatches: ValidationMismatch[];
    version: string;
    endpoint_id: string | null;
    endpoint_revision: number | null;
    validated_at: string;
    can_publish: boolean;
};

export type StartedValidation = {
    operation_id: string;
    status: string;
    expires_at: string;
    draft_revision: number;
};

// The summary of a finished `publish_validation` Operation.
export type ValidationSummary = {
    ok: boolean;
    status: ValidationStatus | null;
    reason: string | null;
    report_id: string | null;
    mismatches: number;
    draft_revision: number | null;
};

const url = (draftId: string): string =>
    `${DRAFT_BLOCKS_URL}/${encodeURIComponent(draftId)}/validation`;

// The Draft's latest report, or null when it was never validated.
export async function fetchValidationReport(
    draftId: string,
    signal?: AbortSignal,
): Promise<ValidationReport | null> {
    const body = await wizardCall('GET', url(draftId), undefined, signal);

    if (body.data === null) {
        return null;
    }

    const data = body.data as Partial<ValidationReport> | undefined;

    if (!data || typeof data.status !== 'string') {
        throw new WizardError(500);
    }

    // A malformed list is an empty one, never a crash.
    return {
        ...(data as ValidationReport),
        mismatches: Array.isArray(data.mismatches) ? data.mismatches : [],
    };
}

// Starts a validation (`202` with the Operation to poll). The Data Source and the Endpoint are the Draft's own; only the dates and the lock claim
// travel. Throws the 422 with its reason, the 429 with `retry_after` in its body, the 423.
export async function startValidation(
    draftId: string,
    dates: {
        range: { from: string; to: string } | null;
        period: { start: string; end: string } | null;
    },
    claim: SampleClaim,
): Promise<StartedValidation> {
    const answer = await wizardCall('POST', url(draftId), {
        ...dates,
        ...claim,
    });
    const data = answer.data as Partial<StartedValidation> | undefined;

    if (!data || typeof data.operation_id !== 'string') {
        throw new WizardError(500);
    }

    return data as StartedValidation;
}

// Whether the report is for the revision the Draft is at now: any later autosave moves the Draft on and the report reads stale.
export function effectiveStatus(
    report: ValidationReport | null,
    draftRevision: number | null,
): ValidationStatus | null {
    if (report === null) {
        return null;
    }

    return draftRevision !== null && report.subject_revision !== draftRevision
        ? 'stale'
        : report.status;
}

// Why Publish cannot happen yet; undefined when the report allows it. The order is what the Admin can act on first.
export type PublishReason =
    | 'no_source'
    | 'not_validated'
    | 'running'
    | 'failed'
    | 'unreachable'
    | 'stale'
    | 'strict';

export function publishReason(
    hasSource: boolean,
    report: ValidationReport | null,
    draftRevision: number | null,
    running: boolean,
): PublishReason | undefined {
    if (!hasSource) {
        return 'no_source';
    }

    if (running) {
        return 'running';
    }

    const status = effectiveStatus(report, draftRevision);

    if (status === null) {
        return 'not_validated';
    }

    if (status === 'passed') {
        return undefined;
    }

    if (status === 'failed' && report?.reason === 'strict_schema') {
        return 'strict';
    }

    return status;
}
