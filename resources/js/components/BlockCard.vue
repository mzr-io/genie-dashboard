<script setup lang="ts">
import {
    EllipsisVertical,
    Minus,
    Plus,
    RefreshCw,
    ArrowUpRight,
} from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { iconFor } from '@/lib/blockIcons';
import { rendererFor } from '@/lib/blockRenderers';
import { kpiPayloadOf } from '@/lib/dashboards';
import type { DashboardItem, ItemResult } from '@/lib/dashboards';
import { resolveFooterLink } from '@/lib/footerAction';
import type { FooterProfile } from '@/lib/footerAction';
import { formatDateTime } from '@/lib/format';
import { addBlocksLabels, dashboardLabels as labels } from '@/locales/labels';

// One Block on a dashboard (Story 4.4 to 4.6; UX-DR-50, 51, 231): a `section` labelled by its `h2` title, on the card surface with a 1px border and the
// large radius. The server decides the state. An item that is not `ok` shows its message and a Remove action and no data. A Block with no result yet
// shows its real header and its own skeleton; one with a result draws it with its Block Type's Renderer, which implements every state and writes each
// slot as literal text. A later result replaces the last one in place. The header carries the controls the Admin allowed (Refresh, Minimize, the ⋯ menu,
// each with its Block's name and a 28 px hit area); Minimize leaves the header only. The body is the focusable region that scrolls when its content
// overflows, never a clipping box (UX-DR-149). Remove is in place and says it is not available yet: the layout commands arrive with Story 4.9.
const props = withDefaults(
    defineProps<{
        item: DashboardItem;
        result?: ItemResult | null;
        // The member's own minimize choice, once made; the layout's value otherwise.
        minimized?: boolean | null;
        // A manual refresh is in flight for this Block.
        refreshing?: boolean;
        // The manual-refresh window is open: Refresh is aria-disabled and says why.
        refreshLimited?: boolean;
        // Admins also see technical details in About this block; a User never does.
        admin?: boolean;
        // Just added to the dashboard: outlined, with "Just added", for a few seconds.
        justAdded?: boolean;
        // The profile `{user.*}` footer tokens are filled from.
        profile?: FooterProfile;
    }>(),
    {
        result: null,
        minimized: null,
        refreshing: false,
        refreshLimited: false,
        admin: false,
        justAdded: false,
        profile: () => ({}),
    },
);

const emit = defineEmits<{
    retry: [itemId: string];
    refresh: [itemId: string];
    minimize: [itemId: string, minimized: boolean];
    remove: [blockId: string, from: 'menu' | 'card'];
}>();

const { t } = useI18n();
const headingId = useId();
const limitedId = useId();
const aboutOpen = ref(false);

// The state to show: the layout's unless the results say the Block has since become unavailable.
const state = computed(() =>
    props.item.state !== 'ok'
        ? props.item.state
        : props.result?.state === 'access_removed' ||
            props.result?.state === 'unpublished'
          ? props.result.state
          : 'ok',
);
const unavailable = computed(() => state.value !== 'ok');
const title = computed(() => props.item.block?.name ?? labels.unavailableBlock);
const message = computed(() =>
    state.value === 'access_removed'
        ? t('block-access-removed')
        : t('block-unpublished'),
);
const chrome = computed(() => props.item.block?.chrome ?? null);
const showHeader = computed(
    () => unavailable.value || chrome.value?.show_header !== false,
);
const subtitle = computed(() =>
    chrome.value?.show_subtitle === false
        ? null
        : (props.item.block?.subtitle ?? null),
);
const icon = computed(() => iconFor(props.item.block?.icon));
const isMinimized = computed(() => props.minimized ?? props.item.minimized);
const canRefresh = computed(
    () => !unavailable.value && chrome.value?.allow_refresh === true,
);
const canMinimize = computed(
    () => !unavailable.value && chrome.value?.allow_minimize === true,
);
const showMenu = computed(() => !unavailable.value);

const renderer = computed(() =>
    props.item.block === null ? null : rendererFor(props.item.block.type),
);
const payload = computed(() =>
    props.result === null || props.result === undefined
        ? null
        : kpiPayloadOf(props.result),
);

const footer = computed(() => {
    const configured = props.item.block?.footer ?? null;

    if (
        configured === null ||
        unavailable.value ||
        chrome.value?.show_footer_action !== true
    ) {
        return null;
    }

    const link = resolveFooterLink(configured.target, props.profile);

    return link === null ? null : { ...link, label: configured.label };
});

// "About this block": the description, the Data Source, the interval in words, when the data is from (full date and zone), the version and how the
// period is followed. Technical details (why a slot is unavailable) are for Admins only.
function intervalText(value: number | 'live' | null): string {
    if (value === 'live') {
        return labels.about.live;
    }

    if (value === null) {
        return labels.about.none;
    }

    for (const [size, unit] of [
        [3600, 'hour'],
        [60, 'minute'],
    ] as const) {
        if (value % size === 0) {
            return labels.about.every(value / size, unit);
        }
    }

    return labels.about.every(value, 'second');
}

const aboutRows = computed(() => {
    const about = props.item.block?.about;

    if (!about) {
        return [];
    }

    const asOf = props.result?.data_as_of ?? null;

    return [
        [labels.about.description, about.description ?? labels.about.none],
        [labels.about.source, about.source_name ?? labels.about.none],
        [labels.about.interval, intervalText(about.refresh_interval)],
        [
            labels.about.asOf,
            asOf === null
                ? labels.about.none
                : // `dateStyle` and `timeStyle` cannot be combined with `timeZoneName`, so the parts are named.
                  formatDateTime(asOf, {
                      day: 'numeric',
                      month: 'long',
                      year: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                      timeZoneName: 'short',
                  }),
        ],
        [labels.about.version, about.version],
        [
            labels.about.period,
            about.period === null
                ? labels.about.none
                : labels.about.periods[about.period],
        ],
    ] as Array<[string, string]>;
});

const technical = computed(() =>
    props.admin
        ? Object.entries(props.result?.slot_states ?? {})
              .filter(([, slot]) => slot.state === 'unavailable')
              .map(([key, slot]) => `${key}: ${slot.reason ?? 'unavailable'}`)
        : [],
);

function refresh(): void {
    if (props.refreshLimited || props.refreshing) {
        return;
    }

    emit('refresh', props.item.item_id);
}
</script>

<template>
    <section
        data-slot="block-card"
        :data-state="state"
        :data-minimized="isMinimized ? 'true' : undefined"
        :data-just-added="justAdded ? 'true' : undefined"
        :aria-labelledby="headingId"
        class="flex min-h-0 flex-col overflow-hidden rounded-lg border bg-surface-card"
        :class="
            justAdded
                ? 'border-accent bg-success-soft outline outline-2 outline-accent'
                : 'border-border-default'
        "
    >
        <header
            class="flex items-start justify-between gap-2 px-4 py-3"
            :class="showHeader ? '' : 'sr-only'"
        >
            <div class="flex min-w-0 items-start gap-2">
                <span
                    v-if="!unavailable"
                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md bg-surface-muted text-text-secondary"
                    aria-hidden="true"
                    data-slot="block-icon"
                >
                    <component
                        :is="refreshing ? RefreshCw : icon"
                        class="size-4"
                        :class="refreshing ? 'animate-spin' : ''"
                    />
                </span>
                <div class="min-w-0">
                    <h2
                        :id="headingId"
                        class="type-title-sm truncate text-text-primary"
                    >
                        {{ title }}
                    </h2>
                    <p v-if="subtitle" class="type-caption text-text-muted">
                        {{ subtitle }}
                    </p>
                    <p
                        v-if="justAdded"
                        class="type-caption font-semibold text-success-text"
                        data-slot="just-added"
                    >
                        {{ addBlocksLabels.justAdded }}
                    </p>
                </div>
            </div>

            <div
                v-if="canRefresh || canMinimize || showMenu"
                class="flex shrink-0 items-center gap-0.5"
                data-slot="block-controls"
            >
                <button
                    v-if="canRefresh"
                    type="button"
                    class="flex size-7 items-center justify-center rounded-md text-text-secondary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="labels.refresh(title)"
                    :aria-disabled="refreshLimited ? 'true' : undefined"
                    :aria-describedby="refreshLimited ? limitedId : undefined"
                    :aria-busy="refreshing ? 'true' : undefined"
                    data-slot="block-refresh"
                    @click="refresh"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                </button>
                <span v-if="refreshLimited" :id="limitedId" class="sr-only">{{
                    t('refresh-limited')
                }}</span>
                <button
                    v-if="canMinimize"
                    type="button"
                    class="flex size-7 items-center justify-center rounded-md text-text-secondary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="
                        isMinimized
                            ? labels.expand(title)
                            : labels.minimize(title)
                    "
                    :aria-expanded="isMinimized ? 'false' : 'true'"
                    data-slot="block-minimize"
                    @click="emit('minimize', item.item_id, !isMinimized)"
                >
                    <Plus
                        v-if="isMinimized"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <Minus v-else class="size-4" aria-hidden="true" />
                </button>
                <DropdownMenu v-if="showMenu">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex size-7 items-center justify-center rounded-md text-text-secondary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="labels.moreActions(title)"
                            data-slot="block-menu"
                        >
                            <EllipsisVertical
                                class="size-4"
                                aria-hidden="true"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            v-if="canRefresh"
                            :disabled="refreshLimited"
                            @select="refresh"
                        >
                            {{ labels.menuRefresh }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="aboutOpen = true">
                            {{ labels.menuAbout }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            @select="emit('remove', item.block_id, 'menu')"
                        >
                            {{ labels.menuRemove }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <div
            v-if="!isMinimized"
            :aria-label="labels.bodyRegion(title)"
            role="region"
            tabindex="0"
            class="min-h-0 flex-1 overflow-auto px-4 pb-4"
            data-slot="block-body"
        >
            <div
                v-if="!unavailable && payload !== null && renderer !== null"
                data-slot="block-result"
                :data-etag="result?.result_etag ?? undefined"
            >
                <component
                    :is="renderer"
                    :payload="payload"
                    :audience="admin ? 'admin' : 'user'"
                    bare
                    @retry="$emit('retry', item.item_id)"
                />
            </div>
            <div
                v-else-if="!unavailable"
                role="status"
                aria-busy="true"
                class="grid gap-2"
                data-slot="block-skeleton"
            >
                <span class="sr-only">{{ labels.loadingBlock(title) }}</span>
                <Skeleton class="h-3 w-1/2" :caption="false" />
                <Skeleton class="h-8 w-3/4" :caption="false" />
                <Skeleton class="h-3 w-1/3" />
            </div>
            <div
                v-else
                class="flex flex-col items-start gap-3"
                data-slot="block-unavailable"
            >
                <p class="type-body text-text-secondary">{{ message }}</p>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :aria-label="labels.removeBlock(title)"
                    @click="emit('remove', item.block_id, 'card')"
                >
                    {{ labels.remove }}
                </Button>
            </div>
        </div>

        <footer
            v-if="footer && !isMinimized"
            class="px-4 pb-3 text-center"
            data-slot="block-footer"
        >
            <a
                :href="footer.href"
                class="type-caption inline-flex items-center gap-1 font-semibold text-accent-ink hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :target="footer.external ? '_blank' : undefined"
                :rel="footer.external ? 'noopener noreferrer' : undefined"
                :aria-label="
                    footer.external
                        ? labels.footerLinkExternal(footer.label)
                        : undefined
                "
            >
                {{ footer.label }}
                <ArrowUpRight
                    v-if="footer.external"
                    class="size-3.5"
                    aria-hidden="true"
                />
            </a>
        </footer>

        <Dialog v-model:open="aboutOpen">
            <DialogContent data-slot="about-dialog">
                <DialogHeader>
                    <DialogTitle>{{ labels.about.title }}</DialogTitle>
                    <DialogDescription>{{ title }}</DialogDescription>
                </DialogHeader>
                <dl class="grid gap-3">
                    <div
                        v-for="[term, value] in aboutRows"
                        :key="term"
                        class="grid gap-0.5"
                    >
                        <dt class="type-caption text-text-muted">{{ term }}</dt>
                        <dd class="type-body-sm break-words text-text-primary">
                            {{ value }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="technical.length > 0"
                    class="grid gap-1"
                    data-slot="about-technical"
                >
                    <h3 class="type-caption text-text-muted">
                        {{ labels.about.technical }}
                    </h3>
                    <ul class="type-body-sm text-text-primary">
                        <li v-for="line in technical" :key="line">
                            {{ line }}
                        </li>
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
