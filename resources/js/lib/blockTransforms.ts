import type { SampleClaim } from '@/lib/blockSample';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';
import { wizardCall } from '@/lib/blockWizard';

// The row transforms of a Draft (Story 5.1, AD-11): Filter, Sort and Top-N steps, stored by the server as `config.transforms` and carried into the Query
// Plan. The stage order is fixed by the server (filter, sort, top-N), whatever order steps were added in; nothing here holds a script or an expression.
export const OPERATORS = [
    'is',
    'is_not',
    'contains',
    'gt',
    'gte',
    'lt',
    'lte',
] as const;
export type Operator = (typeof OPERATORS)[number];
export const NUMERIC_OPERATORS: readonly Operator[] = [
    'gt',
    'gte',
    'lt',
    'lte',
];

export type FilterStep = {
    type: 'filter';
    field: string;
    operator: Operator;
    value: string;
};
export const FUNCTIONS = ['sum', 'count', 'avg', 'min', 'max'] as const;
export type AggregateFunction = (typeof FUNCTIONS)[number];

export type GroupStep = { type: 'group'; fields: string[] };
export type AggregateStep = {
    type: 'aggregate';
    function: AggregateFunction;
    field: string;
};
export type SortStep = {
    type: 'sort';
    field: string;
    direction: 'asc' | 'desc';
};
export type TopNStep = { type: 'top_n'; count: number };
export type TransformStep =
    | FilterStep
    | GroupStep
    | AggregateStep
    | SortStep
    | TopNStep;
export type StepType = TransformStep['type'];

// A step the sample cannot satisfy, as the server reports it: `index` is the position in the stored (stage) order.
export type TransformFault = {
    index: number;
    type: StepType;
    field: string | null;
    reason: 'field_missing' | 'not_numeric' | 'unsortable';
    // An aggregate that cannot read the field (Story 5.2): which function, and what kind of field it met.
    function?: string | null;
    field_type?: string | null;
};

// How many rows were before the steps and after each of them.
export type RowCounts = { input: number; steps: number[] };

export type TransformWrite = {
    transforms: TransformStep[];
    draft_revision: number;
    query_plan: unknown;
    transform_faults: TransformFault[];
};

export const MAX_FILTERS = 5;
export const MAX_SORTS = 3;
export const MAX_GROUP_FIELDS = 3;
export const MAX_AGGREGATES = 5;
export const MAX_TOP_N = 1000;
export const VALUE_MAX = 200;
export const ROOT = 'config.transforms';

const DECIMAL = /^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/;
const ORDER: Record<StepType, number> = {
    filter: 0,
    group: 1,
    aggregate: 2,
    sort: 3,
    top_n: 4,
};

// The fixed stage order, each stage keeping the order steps were added in: what the server stores and runs.
export function inStageOrder(steps: TransformStep[]): TransformStep[] {
    return steps
        .map((step, position) => ({ step, position }))
        .sort(
            (a, b) =>
                ORDER[a.step.type] - ORDER[b.step.type] ||
                a.position - b.position,
        )
        .map((entry) => entry.step);
}

export function canAdd(steps: TransformStep[], type: StepType): boolean {
    const used = steps.filter((s) => s.type === type).length;

    const limit =
        type === 'filter'
            ? MAX_FILTERS
            : type === 'sort'
              ? MAX_SORTS
              : type === 'aggregate'
                ? MAX_AGGREGATES
                : 1;

    return used < limit;
}

// The last name of a stored path: `$.data[*].status` is `status`.
export function shortName(path: string): string {
    const match = /([A-Za-z_][A-Za-z0-9_]*)(?:\[\*\])?$/.exec(path);

    return match?.[1] ?? path;
}

export function sameSteps(a: TransformStep[], b: TransformStep[]): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

// Faults of a step by what the client knows (the server judges the same against the sample): a value that is not a number for a numeric comparison,
// an empty or out-of-range count. Keyed by the step's position.
export function judge(steps: TransformStep[]): Record<number, string> {
    const out: Record<number, string> = {};

    steps.forEach((step, index) => {
        if (step.type === 'filter') {
            if (step.value.length > VALUE_MAX) {
                out[index] = 'value_long';
            } else if (
                NUMERIC_OPERATORS.includes(step.operator) &&
                !DECIMAL.test(step.value.trim())
            ) {
                out[index] = 'not_numeric_value';
            }
        } else if (step.type === 'group') {
            if (
                step.fields.length === 0 ||
                new Set(step.fields).size !== step.fields.length
            ) {
                out[index] = 'group_fields';
            }
        } else if (step.type === 'top_n') {
            if (
                !Number.isInteger(step.count) ||
                step.count < 1 ||
                step.count > MAX_TOP_N
            ) {
                out[index] = 'count';
            }
        }
    });

    return out;
}

// `PUT .../mapping/transforms`: the steps, whole; `[]` clears them. A 422 `blocks.invalid_config` names each fault as `config.transforms.<n>.<part>`.
export async function putTransforms(
    draftId: string,
    transforms: TransformStep[],
    revision: number,
    claim: SampleClaim,
): Promise<TransformWrite> {
    const answer = await wizardCall(
        'PUT',
        `${DRAFT_BLOCKS_URL}/${encodeURIComponent(draftId)}/mapping/transforms`,
        { transforms, draft_revision: revision, ...claim },
    );

    return answer.data as TransformWrite;
}
