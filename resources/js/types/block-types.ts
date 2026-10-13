import type { BlockType } from './block-type-shape';

export * from './block-type-shape';

// The Block Types of the application (Story 5.17): every package under `block-types/` brings its own generated `block-type.generated.ts`, found here
// by its place, so adding a package changes no other file. The shape is what `GET /api/v1/block-types` returns.
const modules = import.meta.glob(
    '../../../block-types/*/v*/block-type.generated.ts',
    { eager: true },
) as Record<string, Record<string, unknown>>;

const isBlockType = (value: unknown): value is BlockType =>
    typeof value === 'object' &&
    value !== null &&
    typeof (value as BlockType).key === 'string' &&
    Array.isArray((value as BlockType).slots);

// Every contract version of every package, newest last within a key.
export const ALL_BLOCK_TYPES: BlockType[] = Object.values(modules)
    .flatMap((module) => Object.values(module).filter(isBlockType))
    .sort(
        (a, b) =>
            a.key.localeCompare(b.key) ||
            a.contract_version - b.contract_version,
    );

// The newest contract of each key, the KPI card first (it is what a new Block starts as), then by title.
export const LATEST_BLOCK_TYPES: BlockType[] = Object.values(
    ALL_BLOCK_TYPES.reduce<Record<string, BlockType>>((latest, type) => {
        latest[type.key] = type;

        return latest;
    }, {}),
).sort(
    (a, b) =>
        Number(b.key === 'kpi-card') - Number(a.key === 'kpi-card') ||
        a.title.localeCompare(b.title),
);

export const blockTypeByKey = (
    key: string,
    contractVersion?: number,
): BlockType | undefined =>
    ALL_BLOCK_TYPES.filter(
        (type) =>
            type.key === key &&
            (contractVersion === undefined ||
                type.contract_version === contractVersion),
    ).pop();

export const kpiCardV1 = blockTypeByKey('kpi-card', 1) as BlockType;
