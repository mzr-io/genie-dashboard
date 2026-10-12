<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import BlockThumbnail from '@/components/BlockThumbnail.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { rendererFor } from '@/lib/blockRenderers';
import { kpiPayloadOf } from '@/lib/dashboards';
import { announceDebounced } from '@/lib/announce';
import {
    CatalogueError,
    NO_FILTERS,
    categoriesOf,
    fetchCatalogue,
    fetchSample,
    highlight,
    matches,
} from '@/lib/blockCatalogue';
import type {
    BlockSample,
    CatalogueBlock,
    Filters,
} from '@/lib/blockCatalogue';
import { SIGN_IN_URL } from '@/lib/session';
import { addBlocksLabels as labels } from '@/locales/labels';

// The Add-blocks Panel (Story 4.7; FR-52, UX-DR-67 to 69, 213, 214, 221, 222): the Blocks a member may add, as a list with search (names, descriptions,
// categories, highlighted as text), category chips, a type and Data Source filter, an announced count, and each row's status. Adding and removing
// are wired by Story 4.9 and say so; there are no drag grips, no multi-add and no "Request a block". The panel only reads: it creates no target and
// no subscription, and shows no value.
const props = defineProps<{
    // Block ids already on the dashboard: they show "✓ Added" and keep their place in the list.
    addedIds: string[];
}>();

const emit = defineEmits<{ close: []; skip: [] }>();

const { t } = useI18n();
const searchId = useId();
const reasonId = useId();

const state = ref<'loading' | 'ready' | 'error'>('loading');
const blocks = ref<CatalogueBlock[]>([]);
const filters = ref<Filters>({ ...NO_FILTERS });
const open = ref<string | null>(null);
const searchBox = (): HTMLElement | null => document.getElementById(searchId);
let controller: AbortController | null = null;

const categories = computed(() => categoriesOf(blocks.value));
const types = computed(() =>
    Array.from(new Set(blocks.value.map((block) => block.type))).sort(),
);
const sources = computed(() =>
    Array.from(
        new Set(
            blocks.value
                .map((block) => block.data_source)
                .filter((name): name is string => name !== null),
        ),
    ).sort((a, b) => a.localeCompare(b)),
);
const shown = computed(() =>
    blocks.value.filter((block) => matches(block, filters.value)),
);
const filtered = computed(
    () =>
        filters.value.query.trim() !== '' ||
        filters.value.category !== null ||
        filters.value.type !== null ||
        filters.value.source !== null,
);
const count = computed(() =>
    filtered.value
        ? labels.countFiltered(shown.value.length, blocks.value.length)
        : labels.countAll(blocks.value.length),
);
const disabled = computed(() => state.value !== 'ready');

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    state.value = 'loading';

    try {
        const result = await fetchCatalogue(mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        blocks.value = result;
        state.value = 'ready';
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (
            error instanceof CatalogueError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        state.value = 'error';
    }
}

function clear(): void {
    filters.value = { ...NO_FILTERS };
    searchBox()?.focus();
}

// One preview is open at a time. Its sample comes from the server's generator (never a source) and is the same every time for a version.
const samples = ref<Record<string, BlockSample | 'placeholder' | 'loading'>>(
    {},
);

async function toggle(blockId: string): Promise<void> {
    if (open.value === blockId) {
        open.value = null;

        return;
    }

    open.value = blockId;

    if (samples.value[blockId] === undefined) {
        samples.value = { ...samples.value, [blockId]: 'loading' };

        const sample = await fetchSample(blockId);

        samples.value = {
            ...samples.value,
            [blockId]: sample ?? 'placeholder',
        };
    }
}

// What the Renderer draws for a block's sample; a sample that cannot be shown falls back to a labelled placeholder, never to 0.
function previewPayload(blockId: string) {
    const sample = samples.value[blockId];

    if (
        sample === undefined ||
        sample === 'loading' ||
        sample === 'placeholder'
    ) {
        return null;
    }

    return kpiPayloadOf({
        item_id: blockId,
        block_id: blockId,
        state: 'ok',
        stale: false,
        refreshing: false,
        data_as_of: null,
        last_success_at: null,
        stale_at: null,
        result_etag: null,
        slot_states: {},
        render_payload: sample.render_payload,
    });
}

function previewRenderer(blockId: string) {
    const sample = samples.value[blockId];

    return sample === undefined ||
        sample === 'loading' ||
        sample === 'placeholder'
        ? null
        : rendererFor(sample.type);
}

// A click on the row's text opens its preview too; the controls inside it keep their own action.
function onBody(event: MouseEvent, blockId: string): void {
    if (
        !(event.target instanceof Element) ||
        event.target.closest('button, a') !== null
    ) {
        return;
    }

    void toggle(blockId);
}

// Esc collapses an open preview; with none open it closes the drawer.
function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape') {
        return;
    }

    if (open.value !== null) {
        const id = open.value;
        open.value = null;
        event.stopPropagation();
        document
            .querySelector<HTMLElement>(
                `[data-block="${CSS.escape(id)}"] [data-slot=row-disclosure]`,
            )
            ?.focus();
    } else {
        emit('close');
    }
}

function typeLabel(type: string): string {
    return labels.types[type] ?? type;
}

function intervalText(value: number | 'live' | null): string {
    if (value === 'live') {
        return 'Live';
    }

    if (value === null) {
        return '—';
    }

    if (value % 3600 === 0) {
        return value === 3600 ? 'hourly' : `every ${value / 3600} hours`;
    }

    return value % 60 === 0
        ? value === 60
            ? 'every minute'
            : `every ${value / 60} minutes`
        : `every ${value} seconds`;
}

function meta(block: CatalogueBlock): string {
    const size = block.default_size;

    return `Data source: ${block.data_source ?? '—'} · Refreshes ${intervalText(block.refresh_interval)} · v${block.version} · Default size ${size.width} columns × ${size.height.charAt(0).toUpperCase()}${size.height.slice(1)}`;
}

// The count is announced politely, once the person has stopped changing the search for about half a second.
let counted = '';
// The first count is on screen already; only a change is announced.
watch(count, (next) => {
    if (state.value !== 'ready' || next === counted) {
        return;
    }

    if (counted !== '') {
        announceDebounced('add-blocks-count', next);
    }

    counted = next;
});

onMounted(async () => {
    await load();
    await nextTick();
    searchBox()?.focus();
});

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col bg-surface-card"
        data-slot="add-blocks-panel"
        @keydown="onKeydown"
    >
        <header
            class="flex flex-col gap-1 border-b border-border-default px-4 py-3"
        >
            <div class="flex items-start justify-between gap-2">
                <h2 class="type-title-sm text-text-primary">
                    {{ labels.title }} — {{ labels.subtitle }}
                </h2>
            </div>
            <a
                href="#"
                class="type-caption w-fit text-accent-ink underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-slot="skip-to-dashboard"
                @click.prevent="emit('skip')"
            >
                {{ labels.skipLink }}
            </a>
        </header>

        <div
            class="flex flex-col gap-2 border-b border-border-default px-4 py-3"
        >
            <label :for="searchId" class="sr-only">{{ labels.search }}</label>
            <Input
                :id="searchId"
                v-model="filters.query"
                type="search"
                :placeholder="labels.searchPlaceholder"
                :disabled="disabled"
                :aria-describedby="disabled ? reasonId : undefined"
                data-slot="add-blocks-search"
            />

            <div
                role="group"
                :aria-label="labels.categories"
                class="flex flex-wrap gap-1.5"
                data-slot="category-chips"
            >
                <button
                    type="button"
                    class="type-caption rounded-full border px-3 py-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        filters.category === null
                            ? 'border-accent bg-accent-soft text-accent-ink'
                            : 'border-border-default text-text-secondary'
                    "
                    :aria-pressed="filters.category === null"
                    :disabled="disabled"
                    @click="filters.category = null"
                >
                    {{ labels.allCategories }}
                </button>
                <button
                    v-for="name in categories"
                    :key="name"
                    type="button"
                    class="type-caption rounded-full border px-3 py-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        filters.category === name
                            ? 'border-accent bg-accent-soft text-accent-ink'
                            : 'border-border-default text-text-secondary'
                    "
                    :aria-pressed="filters.category === name"
                    :disabled="disabled"
                    @click="filters.category = name"
                >
                    {{ name }}
                </button>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <label class="grid gap-1">
                    <span class="type-caption text-text-muted">{{
                        labels.filterType
                    }}</span>
                    <select
                        v-model="filters.type"
                        class="min-h-9 w-full rounded-md border border-border-control bg-surface-card px-2 text-sm text-text-primary disabled:opacity-45"
                        :disabled="disabled"
                        data-slot="filter-type"
                    >
                        <option :value="null">{{ labels.anyType }}</option>
                        <option v-for="type in types" :key="type" :value="type">
                            {{ typeLabel(type) }}
                        </option>
                    </select>
                </label>
                <label class="grid gap-1">
                    <span class="type-caption text-text-muted">{{
                        labels.filterSource
                    }}</span>
                    <select
                        v-model="filters.source"
                        class="min-h-9 w-full rounded-md border border-border-control bg-surface-card px-2 text-sm text-text-primary disabled:opacity-45"
                        :disabled="disabled"
                        data-slot="filter-source"
                    >
                        <option :value="null">{{ labels.anySource }}</option>
                        <option
                            v-for="name in sources"
                            :key="name"
                            :value="name"
                        >
                            {{ name }}
                        </option>
                    </select>
                </label>
            </div>

            <p
                v-if="state === 'ready'"
                class="type-caption text-text-muted"
                data-slot="add-blocks-count"
            >
                {{ count }}
            </p>
            <p
                v-if="disabled && state === 'error'"
                :id="reasonId"
                class="sr-only"
            >
                {{ labels.unavailableReason }}
            </p>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
            <div
                v-if="state === 'loading'"
                role="status"
                aria-busy="true"
                class="grid gap-3"
                data-slot="add-blocks-loading"
            >
                <span class="sr-only">{{ labels.loading }}</span>
                <div v-for="n in 4" :key="n" class="flex gap-3">
                    <Skeleton
                        class="h-[46px] w-16 shrink-0 rounded-md"
                        :caption="false"
                    />
                    <div class="grid flex-1 gap-1.5">
                        <Skeleton class="h-3 w-2/3" :caption="false" />
                        <Skeleton class="h-3 w-full" :caption="false" />
                    </div>
                </div>
            </div>

            <div
                v-else-if="state === 'error'"
                role="alert"
                class="flex flex-col items-start gap-3"
                data-slot="add-blocks-error"
            >
                <p class="type-body text-text-primary">
                    {{ t('drawer-load-failed') }}
                </p>
                <Button type="button" variant="secondary" @click="load">
                    {{ labels.retry }}
                </Button>
            </div>

            <p
                v-else-if="blocks.length === 0"
                class="type-body text-text-secondary"
                data-slot="add-blocks-none"
            >
                {{ t('drawer-none') }}
            </p>

            <div
                v-else-if="shown.length === 0"
                class="flex flex-col items-start gap-3"
                data-slot="add-blocks-empty-search"
            >
                <p class="type-body text-text-secondary">
                    {{
                        t('drawer-empty-search', {
                            query: filters.query.trim(),
                        })
                    }}
                </p>
                <Button type="button" variant="secondary" @click="clear">
                    {{ labels.clearSearch }}
                </Button>
            </div>

            <ul
                v-else
                class="grid gap-2"
                role="list"
                data-slot="add-blocks-list"
            >
                <li
                    v-for="block in shown"
                    :key="block.block_id"
                    class="rounded-lg border p-3 hover:bg-surface-sunken"
                    :class="
                        open === block.block_id
                            ? 'border-accent shadow-[0_4px_16px_rgba(0,0,0,0.06)]'
                            : 'border-transparent'
                    "
                    :data-block="block.block_id"
                >
                    <div
                        class="grid grid-cols-[64px_1fr_auto] items-start gap-3"
                    >
                        <BlockThumbnail :type="block.type" />

                        <div
                            class="min-w-0"
                            @click="onBody($event, block.block_id)"
                        >
                            <h3
                                class="type-title-sm break-words text-text-primary"
                            >
                                <button
                                    type="button"
                                    class="text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :aria-expanded="open === block.block_id"
                                    :aria-controls="`preview-${block.block_id}`"
                                    :aria-describedby="`desc-${block.block_id}`"
                                    data-slot="row-disclosure"
                                    @click="toggle(block.block_id)"
                                >
                                    <template
                                        v-for="(segment, i) in highlight(
                                            block.name,
                                            filters.query,
                                        )"
                                        :key="i"
                                    >
                                        <mark
                                            v-if="segment.hit"
                                            class="bg-accent-soft text-text-primary"
                                            >{{ segment.text }}</mark
                                        >
                                        <template v-else>{{
                                            segment.text
                                        }}</template>
                                    </template>
                                </button>
                            </h3>
                            <p
                                :id="`desc-${block.block_id}`"
                                class="type-caption line-clamp-2 break-words text-text-secondary"
                            >
                                <template
                                    v-for="(segment, i) in highlight(
                                        block.description ?? '',
                                        filters.query,
                                    )"
                                    :key="i"
                                >
                                    <mark
                                        v-if="segment.hit"
                                        class="bg-accent-soft text-text-primary"
                                        >{{ segment.text }}</mark
                                    >
                                    <template v-else>{{
                                        segment.text
                                    }}</template>
                                </template>
                            </p>
                            <p
                                class="type-caption mt-1 flex flex-wrap items-center gap-1.5"
                            >
                                <span
                                    v-if="block.category"
                                    class="rounded-sm bg-surface-muted px-1.5 py-0.5 text-text-secondary"
                                    data-slot="row-category"
                                    >{{ block.category }}</span
                                >
                                <span
                                    v-if="block.is_new"
                                    class="rounded-sm bg-accent px-1.5 py-0.5 text-on-accent"
                                    data-slot="row-new"
                                    >{{ labels.new }}</span
                                >
                            </p>
                        </div>

                        <div
                            class="flex flex-col items-end gap-1"
                            data-slot="row-actions"
                        >
                            <template
                                v-if="props.addedIds.includes(block.block_id)"
                            >
                                <span
                                    class="type-caption rounded-sm border border-success bg-success-soft px-2 py-0.5 font-semibold text-success-text"
                                    data-slot="row-added"
                                    >{{ labels.added }}</span
                                >
                                <Button
                                    type="button"
                                    variant="secondary"
                                    size="sm"
                                    aria-disabled="true"
                                    :aria-label="labels.removeNamed(block.name)"
                                    data-slot="row-remove"
                                    @click.prevent
                                >
                                    {{ labels.removeFromDashboard }}
                                </Button>
                            </template>
                            <Button
                                v-else
                                type="button"
                                variant="secondary"
                                size="sm"
                                aria-disabled="true"
                                :aria-label="labels.addTo(block.name)"
                                :title="labels.addingNotEnabled"
                                data-slot="row-add"
                                @click.prevent
                            >
                                + {{ labels.add }}
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="open === block.block_id"
                        :id="`preview-${block.block_id}`"
                        class="mt-3 grid gap-2 border-t border-border-default pt-3"
                        data-slot="row-details"
                    >
                        <p class="type-body-sm break-words text-text-primary">
                            {{ block.description ?? '' }}
                        </p>

                        <div
                            role="region"
                            :aria-label="labels.previewRegion(block.name)"
                            class="mx-auto w-full max-w-[340px] rounded-md border border-border-default bg-surface-sunken p-2"
                            data-slot="row-preview"
                        >
                            <div
                                v-if="samples[block.block_id] === 'loading'"
                                role="status"
                                aria-busy="true"
                                class="grid gap-2"
                            >
                                <span class="sr-only">{{
                                    labels.previewLoading
                                }}</span>
                                <Skeleton class="h-3 w-1/2" :caption="false" />
                                <Skeleton class="h-8 w-3/4" :caption="false" />
                            </div>
                            <div
                                v-else-if="
                                    previewPayload(block.block_id) !== null &&
                                    previewRenderer(block.block_id) !== null
                                "
                                inert
                                data-slot="preview-card"
                            >
                                <component
                                    :is="previewRenderer(block.block_id)"
                                    :payload="previewPayload(block.block_id)"
                                    bare
                                />
                            </div>
                            <div
                                v-else
                                class="grid gap-1 py-2"
                                data-slot="preview-placeholder"
                            >
                                <p class="type-caption text-text-muted">
                                    {{ labels.placeholderTitle }}
                                </p>
                                <p class="type-headline-md text-text-secondary">
                                    {{ labels.placeholderValue }}
                                </p>
                            </div>
                            <p
                                class="type-caption mt-2 text-text-muted"
                                data-slot="sample-note"
                            >
                                {{ t('sample-drawer') }}
                            </p>
                        </div>

                        <p class="type-caption break-words text-text-muted">
                            {{ meta(block) }}
                        </p>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                aria-disabled="true"
                                :aria-label="labels.addTo(block.name)"
                                :title="labels.addingNotEnabled"
                                data-slot="preview-add"
                                @click.prevent
                            >
                                {{ labels.addToDashboard }}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                data-slot="preview-close"
                                @click="toggle(block.block_id)"
                            >
                                {{ labels.closePreview }}
                            </Button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <footer
            class="flex items-center justify-between gap-3 border-t border-border-default px-4 py-3"
        >
            <p class="type-caption text-text-muted">{{ t('drawer-footer') }}</p>
            <Button type="button" @click="emit('close')">
                {{ labels.done }}
            </Button>
        </footer>
    </div>
</template>
