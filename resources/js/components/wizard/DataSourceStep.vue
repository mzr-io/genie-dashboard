<script setup lang="ts">
import { Database, ListTree, RefreshCw } from '@lucide/vue';
import { computed, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Banner from '@/components/Banner.vue';
import BlockedReason from '@/components/BlockedReason.vue';
import DataSourceHealth from '@/components/DataSourceHealth.vue';
import FormField from '@/components/FormField.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import Tag from '@/components/Tag.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SectionCard from '@/components/wizard/SectionCard.vue';
import { useShell } from '@/composables/useShell';
import {
    DATE_PRESETS,
    customRangeError,
    endpointOf,
    endpointText,
    isSharedData,
    paramRows,
    refreshText,
    requestKey,
} from '@/lib/blockSource';
import type { DatePreset, Gate, SourceCatalog } from '@/lib/blockSource';
import type { StepForm } from '@/lib/blockWizard';
import { dataSourceLabels, endpointLabels } from '@/locales/labels';
import { blockSourceLabels as labels } from '@/locales/labels';
import { create, edit } from '@/routes/admin/data-sources';

// Step 2, Data Source (Story 3.7): the Data Source select (a health word on each option and the dot beside the chosen one), the Endpoint list
// (`GET /path`), the POST read-only checkbox, the parameters table, the "shared data" badge, the Refresh Interval and the Default date range.
// The form state and the loaded catalogue belong to the page; this component edits the form in place and reports each edit. A change that
// affects the request (the Data Source, the Endpoint, a parameter value or the default range) marks the sample out of date. Fetch is shown
// with its reasons and does nothing until Story 3.9. Nothing here ever holds a secret: a Data Source's credentials are not part of any read.
const props = defineProps<{
    form: StepForm;
    // The server's field errors, keyed as saved: "source.endpoint_id", "source.bindings.region".
    errors?: Record<string, string | undefined>;
    catalog: SourceCatalog;
    gate: Gate;
    // Why nothing can be fetched right now apart from the form's own reasons (offline); the form's reason wins.
    alsoBlocked?: string;
}>();

const emit = defineEmits<{
    (e: 'change'): void;
    (e: 'retry'): void;
}>();

const { t } = useI18n();
const { can } = useShell();
const sourceId = useId();
const endpointId = useId();
const rangeId = useId();
const refreshId = useId();
const fetchReasonId = useId();
const refreshHelpId = useId();

const source = computed(() => props.form.source);
const mayManage = computed(() => can.value['data_sources.manage'] === true);
const sources = computed(() => props.catalog.sources ?? []);
const chosenSource = computed(
    () =>
        sources.value.find(
            (s) => s.data_source_id === source.value.dataSourceId,
        ) ?? null,
);
const chosenEndpoint = computed(() => endpointOf(props.catalog, source.value));
const rows = computed(() =>
    paramRows(chosenEndpoint.value, source.value, props.form.display.period),
);
const shared = computed(() => isSharedData(chosenEndpoint.value));
const noSources = computed(
    () => props.catalog.sources !== null && props.catalog.sources.length === 0,
);
// Fetch is built in Story 3.9: until then it is always blocked, with the form's own reason first.
const fetchReason = computed(
    () => props.gate.fetch ?? props.alsoBlocked ?? labels.fetchSoon,
);
const rangeError = computed(() => customRangeError(source.value));
const liveCapable = computed(() => chosenSource.value?.live_capable === true);

// A stored Live that this Data Source does not support would make every save a 422: it goes back to unset once the sources are known.
watch(
    () =>
        [
            props.catalog.sources !== null,
            liveCapable.value,
            source.value.refresh,
        ] as const,
    ([loaded, capable, refresh]) => {
        if (loaded && !capable && refresh === 'live') {
            source.value.refresh = null;
            emit('change');
        }
    },
    { immediate: true },
);

// Applies an edit and, when it changed what the request is, marks the sample out of date.
function edited(apply: () => void): void {
    const before = requestKey(source.value);

    apply();

    if (requestKey(source.value) !== before) {
        source.value.outOfDate = true;
    }

    emit('change');
}

function chooseSource(value: string | undefined): void {
    const id = value === undefined || value === '' ? null : value;

    if (id === source.value.dataSourceId) {
        return;
    }

    edited(() => {
        source.value.dataSourceId = id;
        source.value.endpointId = null;
        source.value.bindings = {};
        source.value.readOnlyAck = false;
        source.value.endpointRevisionId = null;
        source.value.dataSourceRevision = null;

        // Live is the Data Source's own capability: another source may not offer it.
        if (
            source.value.refresh === 'live' &&
            !(
                sources.value.find((s) => s.data_source_id === id)
                    ?.live_capable ?? false
            )
        ) {
            source.value.refresh = null;
        }
    });
}

function chooseEndpoint(value: string | undefined): void {
    const id = value === undefined || value === '' ? null : value;

    if (id === source.value.endpointId) {
        return;
    }

    edited(() => {
        source.value.endpointId = id;
        source.value.bindings = {};
        // A POST asks again each time it is chosen.
        source.value.readOnlyAck = false;
        source.value.endpointRevisionId = null;
    });
}

function setBinding(
    name: string,
    value: string,
    endpointValue: string | null,
): void {
    edited(() => {
        if (value === (endpointValue ?? '')) {
            delete source.value.bindings[name];
        } else {
            source.value.bindings[name] = value;
        }
    });
}

function setRefresh(value: string | undefined): void {
    source.value.refresh =
        value === undefined || value === ''
            ? null
            : value === 'live'
              ? 'live'
              : Number(value);
    emit('change');
}

function setPreset(value: string | undefined): void {
    edited(() => {
        source.value.preset = (value ?? '') as DatePreset | '';

        if (source.value.preset !== 'custom') {
            source.value.from = '';
            source.value.to = '';
        }
    });
}

function setDate(edge: 'from' | 'to', value: string): void {
    edited(() => {
        source.value[edge] = value;
    });
}

function setAck(value: boolean | 'indeterminate'): void {
    source.value.readOnlyAck = value === true;
    emit('change');
}

const refreshValue = computed(() =>
    source.value.refresh === null ? '' : String(source.value.refresh),
);

// A stored interval the System settings no longer list stays selectable, so opening the Draft does not change it.
const intervalChoices = computed<number[]>(() => {
    const listed = props.catalog.intervals ?? [];
    const kept = source.value.refresh;

    return typeof kept === 'number' && !listed.includes(kept)
        ? [...listed, kept].sort((a, b) => a - b)
        : listed;
});

const noIntervals = computed(
    () =>
        props.catalog.intervalsLoaded &&
        !props.catalog.intervalsError &&
        (props.catalog.intervals === null ||
            props.catalog.intervals.length === 0),
);

const liveReason = computed(() =>
    liveCapable.value
        ? undefined
        : t('live-not-supported', {
              source: chosenSource.value?.name ?? labels.dataSource,
          }),
);

const isoDay = /^\d{4}-\d{2}-\d{2}$/;
const dateValue = (value: string): string => (isoDay.test(value) ? value : '');
const sourceLabel = (s: { name: string; health: string }) =>
    labels.sourceOption(s.name, dataSourceLabels.health[s.health] ?? s.health);
</script>

<template>
    <div class="flex flex-col gap-4" data-slot="data-source-step">
        <p
            v-if="catalog.sourcesError || catalog.endpointsError"
            role="alert"
            data-test="sources-failed"
            class="type-body-sm flex flex-wrap items-center gap-2 text-error-text"
        >
            {{ labels.loadFailed }}
            <Button
                type="button"
                variant="secondary"
                size="sm"
                data-test="sources-retry"
                @click="emit('retry')"
                >{{ labels.retry }}</Button
            >
        </p>

        <SectionCard
            :title="labels.sourceSection.title"
            :caption="labels.caption"
            :icon="Database"
        >
            <p
                v-if="catalog.sources === null && !catalog.sourcesError"
                role="status"
                class="type-body-sm text-text-muted"
                data-test="sources-loading"
            >
                {{ labels.loading }}
            </p>

            <div
                v-else-if="noSources"
                class="flex flex-col items-start gap-3"
                data-test="datasource-none"
            >
                <p class="type-body text-text-secondary">
                    {{ t('datasource-none.message') }}
                </p>
                <Button v-if="mayManage" as-child variant="secondary">
                    <a
                        :href="create().url"
                        target="_blank"
                        rel="noopener"
                        data-test="register-source"
                        :aria-label="`${t('datasource-none.register')} ${labels.newTab}`"
                        >{{ t('datasource-none.register') }}</a
                    >
                </Button>
                <p
                    v-else
                    class="type-body-sm text-text-muted"
                    data-test="ask-admin"
                >
                    {{ t('datasource-none.ask') }}
                </p>
            </div>

            <template v-else-if="catalog.sources !== null">
                <FormField
                    :id="sourceId"
                    :label="labels.dataSource"
                    :error="errors?.['source.data_source_id']"
                >
                    <template #default="{ field }">
                        <div class="flex flex-wrap items-center gap-3">
                            <NativeSelect
                                v-bind="field"
                                class="max-w-sm"
                                :model-value="source.dataSourceId ?? ''"
                                data-test="data-source"
                                @update:model-value="chooseSource"
                            >
                                <option value="">
                                    {{ labels.dataSourcePlaceholder }}
                                </option>
                                <option
                                    v-for="option in sources"
                                    :key="option.data_source_id"
                                    :value="option.data_source_id"
                                >
                                    {{ sourceLabel(option) }}
                                </option>
                            </NativeSelect>
                            <DataSourceHealth
                                v-if="chosenSource"
                                :status="chosenSource.health"
                            />
                            <Button
                                v-if="mayManage && chosenSource"
                                as-child
                                variant="ghost"
                                size="sm"
                            >
                                <a
                                    :href="
                                        edit(chosenSource.data_source_id).url
                                    "
                                    target="_blank"
                                    rel="noopener"
                                    data-test="edit-source"
                                    :aria-label="`${labels.editSource} ${labels.newTab}`"
                                    >{{ labels.editSource }}</a
                                >
                            </Button>
                        </div>
                    </template>
                </FormField>

                <FormField
                    :id="endpointId"
                    :label="labels.endpoint"
                    :error="errors?.['source.endpoint_id']"
                >
                    <template #default="{ field }">
                        <NativeSelect
                            v-bind="field"
                            class="max-w-xl font-mono"
                            :model-value="source.endpointId ?? ''"
                            :disabled="
                                source.dataSourceId === null ||
                                catalog.endpoints === null
                            "
                            data-test="endpoint"
                            @update:model-value="chooseEndpoint"
                        >
                            <option value="">
                                {{ labels.endpointPlaceholder }}
                            </option>
                            <option
                                v-for="endpoint in catalog.endpoints ?? []"
                                :key="endpoint.endpoint_id"
                                :value="endpoint.endpoint_id"
                            >
                                {{ endpointText(endpoint) }}
                            </option>
                        </NativeSelect>
                    </template>
                </FormField>
                <p
                    v-if="
                        source.dataSourceId !== null &&
                        catalog.endpoints !== null &&
                        catalog.endpoints.length === 0
                    "
                    class="type-caption text-text-muted"
                    data-test="no-endpoints"
                >
                    {{ labels.noEndpoints }}
                </p>

                <div
                    v-if="chosenEndpoint?.method === 'POST'"
                    class="grid gap-1.5"
                    data-slot="post-readonly"
                >
                    <div class="flex items-start gap-2">
                        <Checkbox
                            :id="`${endpointId}-post`"
                            :model-value="source.readOnlyAck"
                            required
                            :aria-describedby="`${endpointId}-post-hint`"
                            class="mt-1"
                            data-test="post-readonly"
                            @update:model-value="setAck"
                        />
                        <Label :for="`${endpointId}-post`">
                            {{ t('post-readonly.label') }}
                            <span aria-hidden="true" class="text-error-text"
                                >*</span
                            >
                        </Label>
                    </div>
                    <p
                        :id="`${endpointId}-post-hint`"
                        class="type-caption text-text-muted"
                    >
                        {{ t('post-readonly.hint') }}
                    </p>
                </div>

                <p
                    v-if="source.dataSourceRevision !== null"
                    class="type-caption font-mono text-text-muted"
                    data-test="pinned"
                >
                    {{ labels.pinned(source.dataSourceRevision) }}
                </p>
            </template>

            <Banner v-if="source.outOfDate" variant="warning">
                <span data-test="out-of-date">{{ labels.outOfDate }}</span>
            </Banner>

            <div class="flex flex-col items-start gap-1">
                <Button
                    type="button"
                    variant="secondary"
                    :blocked="true"
                    :blocked-reason="fetchReason"
                    :aria-describedby="fetchReasonId"
                    data-test="fetch"
                >
                    {{ labels.fetch }}
                </Button>
                <BlockedReason :id="fetchReasonId">{{
                    fetchReason
                }}</BlockedReason>
            </div>
        </SectionCard>

        <SectionCard
            :title="labels.paramsSection.title"
            :caption="labels.paramsSection.caption"
            :icon="ListTree"
        >
            <div class="flex flex-wrap items-center gap-2">
                <Tag
                    v-if="chosenEndpoint && shared"
                    data-test="shared-data"
                    :title="endpointLabels.sharedDataHint"
                    >{{ endpointLabels.sharedData }}</Tag
                >
            </div>
            <p
                v-if="chosenEndpoint === null"
                class="type-body-sm text-text-muted"
                data-test="params-hint"
            >
                {{ labels.paramsNone }}
            </p>
            <p
                v-else-if="rows.length === 0"
                class="type-body-sm text-text-muted"
                data-test="params-none"
            >
                {{ labels.noParams }}
            </p>
            <div v-else class="overflow-x-auto" data-slot="parameters-table">
                <table class="w-full min-w-xl border-collapse text-left">
                    <caption class="sr-only">
                        {{
                            labels.parametersCaption
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                v-for="heading in [
                                    endpointLabels.nameColumn,
                                    endpointLabels.bindingColumn,
                                    endpointLabels.valueColumn,
                                ]"
                                :key="heading"
                                scope="col"
                                class="type-caption pe-2 pb-1 font-semibold text-text-secondary"
                            >
                                {{ heading }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in rows"
                            :key="row.name"
                            class="align-top"
                            data-test="param-row"
                        >
                            <td class="pe-2 pb-2">
                                <span
                                    class="type-body-sm inline-flex min-h-9 items-center font-mono text-text-primary"
                                    data-test="param-name"
                                    >{{ row.name }}</span
                                >
                            </td>
                            <td class="pe-2 pb-2">
                                <span
                                    class="type-body-sm inline-flex min-h-9 items-center"
                                    data-test="param-binding"
                                >
                                    <template v-if="row.unavailable">{{
                                        labels.periodUnavailable
                                    }}</template>
                                    <template v-else>{{
                                        endpointLabels.bindings[row.binding]
                                    }}</template>
                                </span>
                            </td>
                            <td class="pe-2 pb-2">
                                <Input
                                    v-if="row.binding === 'fixed'"
                                    :id="`${endpointId}-param-${index}`"
                                    :model-value="row.value ?? ''"
                                    type="text"
                                    maxlength="2048"
                                    autocomplete="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    :aria-label="labels.valueLabel(row.name)"
                                    data-test="param-value"
                                    @update:model-value="
                                        (value) =>
                                            setBinding(
                                                row.name,
                                                String(value ?? ''),
                                                chosenEndpoint?.params[index]
                                                    ?.value ?? null,
                                            )
                                    "
                                />
                                <span
                                    v-else-if="row.userContext"
                                    class="inline-flex min-h-9 items-center gap-2"
                                    data-test="user-bound"
                                >
                                    <Tag
                                        tone="info"
                                        data-test="user-context-tag"
                                        >{{
                                            endpointLabels.userContextTag
                                        }}</Tag
                                    >
                                    <span
                                        class="type-caption font-mono text-text-muted"
                                        :title="endpointLabels.userContextHint"
                                        data-test="resolved"
                                        >{{
                                            endpointLabels.userContextResolved
                                        }}</span
                                    >
                                </span>
                                <span
                                    v-else
                                    class="type-caption inline-flex min-h-9 items-center font-mono text-text-muted"
                                    data-test="resolved"
                                    >{{ row.resolved ?? '—' }}</span
                                >
                                <p
                                    v-if="
                                        row.binding === 'fixed' &&
                                        errors?.[`source.bindings.${row.name}`]
                                    "
                                    class="type-caption mt-1 text-error-text"
                                    data-slot="field-error"
                                    data-test="param-error"
                                >
                                    {{
                                        errors?.[`source.bindings.${row.name}`]
                                    }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </SectionCard>

        <SectionCard
            :title="labels.refreshSection.title"
            :caption="labels.refreshSection.caption"
            :icon="RefreshCw"
        >
            <FormField
                :id="refreshId"
                :label="labels.refresh"
                :error="errors?.['source.refresh_interval']"
            >
                <template #default="{ field }">
                    <NativeSelect
                        v-bind="field"
                        class="max-w-xs"
                        :model-value="refreshValue"
                        :aria-describedby="
                            [field['aria-describedby'], refreshHelpId]
                                .filter(Boolean)
                                .join(' ')
                        "
                        data-test="refresh"
                        @update:model-value="setRefresh"
                    >
                        <option value="">
                            {{ labels.refreshPlaceholder }}
                        </option>
                        <option
                            value="live"
                            :disabled="!liveCapable"
                            data-test="refresh-live"
                        >
                            {{ labels.live }}
                        </option>
                        <option
                            v-for="seconds in intervalChoices"
                            :key="seconds"
                            :value="String(seconds)"
                        >
                            {{ refreshText(seconds) }}
                        </option>
                    </NativeSelect>
                </template>
            </FormField>
            <div :id="refreshHelpId" class="grid gap-1">
                <p
                    v-if="liveReason"
                    class="type-caption text-text-muted"
                    data-test="live-not-supported"
                >
                    {{ liveReason }}
                </p>
                <p
                    v-if="noIntervals"
                    class="type-caption text-text-muted"
                    data-test="refresh-none"
                >
                    {{ labels.refreshNone }}
                </p>
                <p
                    v-if="catalog.intervalsError"
                    role="alert"
                    class="type-caption text-error-text"
                    data-test="refresh-failed"
                >
                    {{ labels.refreshFailed }}
                </p>
            </div>

            <FormField
                :id="rangeId"
                :label="labels.range"
                :error="errors?.['source.default_date_range']"
            >
                <template #default="{ field }">
                    <NativeSelect
                        v-bind="field"
                        class="max-w-xs"
                        :model-value="source.preset"
                        data-test="date-range"
                        @update:model-value="setPreset"
                    >
                        <option value="">{{ labels.rangePlaceholder }}</option>
                        <option
                            v-for="preset in DATE_PRESETS"
                            :key="preset"
                            :value="preset"
                        >
                            {{ labels.presets[preset] }}
                        </option>
                    </NativeSelect>
                </template>
            </FormField>
            <div
                v-if="source.preset === 'custom'"
                class="grid gap-3 sm:grid-cols-2"
                data-test="custom-range"
            >
                <FormField
                    :id="`${rangeId}-from`"
                    :label="labels.from"
                    :error="
                        rangeError ?? errors?.['source.default_date_range.from']
                    "
                >
                    <template #default="{ field }">
                        <Input
                            v-bind="field"
                            type="date"
                            :model-value="dateValue(source.from)"
                            data-test="range-from"
                            @update:model-value="
                                (value) => setDate('from', String(value ?? ''))
                            "
                        />
                    </template>
                </FormField>
                <FormField
                    :id="`${rangeId}-to`"
                    :label="labels.to"
                    :error="errors?.['source.default_date_range.to']"
                >
                    <template #default="{ field }">
                        <Input
                            v-bind="field"
                            type="date"
                            :model-value="dateValue(source.to)"
                            data-test="range-to"
                            @update:model-value="
                                (value) => setDate('to', String(value ?? ''))
                            "
                        />
                    </template>
                </FormField>
            </div>
        </SectionCard>
    </div>
</template>
