<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
} from 'vue';
import { useI18n } from 'vue-i18n';
import AddAttributeButton from '@/components/AddAttributeButton.vue';
import AddAttributeForm from '@/components/AddAttributeForm.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import FormField from '@/components/FormField.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce, announceDebounced } from '@/lib/announce';
import { SIGN_IN_URL } from '@/lib/session';
import {
    fetchAttributeKeys,
    renameAttributeKey,
    UserAttributesError,
} from '@/lib/userAttributes';
import type { AttributeKey } from '@/lib/userAttributes';
import { userAttributeLabels as labels } from '@/locales/labels';
import { index as settingsIndex } from '@/routes/admin/settings';
import { useToasts } from '@/stores/toasts';

// System settings > User attributes (Story 2.12; UX-DR-115, 263, 23, 282): the catalogue of attributes (a region, an
// employee number) whose values are set per member in User configuration. A data table of key id, label and type with
// search, the count caption, five skeleton rows when cold, `list-empty` with "+ Add attribute", and a failure state with
// Retry. Adding is an inline form; the key id and the type are chosen once. Only the label is edited, inline, under the
// key's revision. The saved row is highlighted, focused and announced politely. There is no delete.
const { t } = useI18n();
const toasts = useToasts();
const SEARCH_DEBOUNCE_MS = 300;

const search = ref('');
const appliedSearch = ref('');
const keys = ref<AttributeKey[]>([]);
const total = ref(0);
const matched = ref(0);
const state = ref<'loading' | 'error' | 'forbidden' | 'ready'>('loading');
const refreshing = ref(false);
const searchId = useId();
const addId = useId();
const adding = ref(false);
const highlighted = ref<string | null>(null);

// Inline rename: the key being edited, its draft label, the field error and whether a save is in flight.
const renaming = ref<string | null>(null);
const draft = ref('');
const renameError = ref<string | null>(null);
const renameSaving = ref(false);

let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
let announceCount = false;

const searching = computed(() => appliedSearch.value.trim() !== '');
const isEmpty = computed(() => state.value === 'ready' && total.value === 0);
const showToolbar = computed(
    () => !isEmpty.value && state.value !== 'forbidden',
);

const columns: DataTableColumn[] = [
    {
        key: 'key_id',
        label: labels.columns.key_id,
        rowHeader: true,
        class: 'font-mono',
    },
    { key: 'label', label: labels.columns.label },
    { key: 'value_type', label: labels.columns.value_type },
    { key: 'actions', label: labels.actions },
];

function signedOut(error: unknown): boolean {
    if (
        error instanceof UserAttributesError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return true;
    }

    return false;
}

function fail(error: unknown): void {
    if (signedOut(error)) {
        return;
    }

    announceCount = false;
    state.value =
        error instanceof UserAttributesError && error.status === 403
            ? 'forbidden'
            : 'error';
    refreshing.value = false;
}

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    if (state.value !== 'ready') {
        state.value = 'loading';
    } else {
        refreshing.value = true;
    }

    try {
        const page = await fetchAttributeKeys(appliedSearch.value, mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        keys.value = page.data;
        total.value = page.meta.total;
        matched.value = page.meta.matched;
        state.value = 'ready';
        refreshing.value = false;

        if (announceCount) {
            announceCount = false;
            announceDebounced(
                'user-attributes-count',
                labels.count(page.meta.matched, page.meta.total),
            );
        }
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        fail(error);
    }
}

function applySearch(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    if (search.value.trim() === appliedSearch.value.trim()) {
        return;
    }

    appliedSearch.value = search.value;
    highlighted.value = null;
    announceCount = true;
    void load();
}

function onSearchInput(): void {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(applySearch, SEARCH_DEBOUNCE_MS);
}

function clearSearch(): void {
    search.value = '';
    applySearch();
    document.getElementById(searchId)?.focus();
}

function clearHighlight(key: string): void {
    if (highlighted.value === key) {
        highlighted.value = null;
    }
}

function rowElement(key: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(`[data-row-key="${key}"]`);
}

// The saved row is highlighted, focused and announced, when it is still listed after the refresh.
async function showSaved(id: string, message: string): Promise<void> {
    announce(`${t('saved')} ${message}`, 'polite');
    announceCount = false;
    await load();
    await nextTick();

    if (state.value === 'ready' && rowElement(id) !== null) {
        highlighted.value = id;
        await nextTick();
        rowElement(id)?.focus();
    }
}

// ---- Add -------------------------------------------------------------------------------------------------------

function toggleAdd(): void {
    adding.value = !adding.value;
}

function closeAdd(): void {
    adding.value = false;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>('[data-test="add-attribute"]')
            ?.focus(),
    );
}

async function added(key: AttributeKey): Promise<void> {
    adding.value = false;
    appliedSearch.value = '';
    search.value = '';
    highlighted.value = null;
    await showSaved(key.id, labels.added(key.label));
}

// ---- Rename ----------------------------------------------------------------------------------------------------

function startRename(row: AttributeKey): void {
    renaming.value = row.id;
    draft.value = row.label;
    renameError.value = null;
    highlighted.value = null;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(`[data-rename-input="${row.id}"]`)
            ?.focus(),
    );
}

function cancelRename(row: AttributeKey): void {
    renaming.value = null;
    renameError.value = null;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(`[data-rename-for="${row.id}"]`)
            ?.focus(),
    );
}

async function saveRename(row: AttributeKey): Promise<void> {
    if (renameSaving.value) {
        return;
    }

    renameSaving.value = true;
    renameError.value = null;

    try {
        const updated = await renameAttributeKey(
            row.key_id,
            draft.value,
            row.revision,
        );

        renaming.value = null;
        await showSaved(updated.id, labels.renamed(updated.label));
    } catch (error) {
        await renameRefused(row, error);
    } finally {
        renameSaving.value = false;
    }
}

async function renameRefused(row: AttributeKey, error: unknown): Promise<void> {
    if (signedOut(error)) {
        return;
    }

    if (error instanceof UserAttributesError) {
        if (error.status === 409 && error.current) {
            // Someone else renamed it: the row shows their label and revision, and what was typed stays in the field.
            keys.value = keys.value.map((key) =>
                key.id === row.id ? (error.current as AttributeKey) : key,
            );
            renameError.value = labels.conflict;
        } else if (error.status === 404) {
            renaming.value = null;
            toasts.add({ kind: 'error', message: labels.gone });
            void load();

            return;
        } else if (error.status === 422 && error.errors.label?.length) {
            renameError.value =
                error.reasons.label === 'duplicate'
                    ? labels.labelTaken
                    : labels.labelInvalid;
        } else {
            toasts.add({
                kind: 'error',
                message:
                    error.status === 429
                        ? t('throttled')
                        : t('save-failed.form'),
            });

            return;
        }
    } else {
        toasts.add({ kind: 'error', message: t('save-failed.form') });

        return;
    }

    await nextTick();
    document
        .querySelector<HTMLElement>(`[data-rename-input="${row.id}"]`)
        ?.focus();
}

onMounted(() => {
    void load();
});

onBeforeUnmount(() => {
    controller?.abort();

    if (timer !== null) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <Head :title="labels.pageTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="labels.pageTitle" :subtitle="labels.pageSubtitle">
            <Link
                :href="settingsIndex().url"
                class="type-body-sm text-text-secondary underline underline-offset-4 hover:text-text-primary"
                data-test="back-to-settings"
                >{{ labels.back }}</Link
            >
            <AddAttributeButton
                v-if="!isEmpty && state === 'ready'"
                :expanded="adding"
                :controls="addId"
                @open="toggleAdd"
            />
        </PageHeader>

        <div v-if="adding" :id="addId">
            <AddAttributeForm @added="added" @close="closeAdd" />
        </div>

        <div
            v-if="showToolbar"
            role="search"
            :aria-label="labels.toolbar"
            data-slot="toolbar"
            class="flex flex-wrap items-center gap-3"
        >
            <label :for="searchId" class="sr-only">{{ labels.search }}</label>
            <Input
                :id="searchId"
                v-model="search"
                type="search"
                maxlength="100"
                autocomplete="off"
                class="max-w-xs"
                :placeholder="labels.searchPlaceholder"
                data-test="search"
                @input="onSearchInput"
                @keydown.enter.prevent="applySearch"
            />
            <p
                v-if="state === 'ready'"
                class="type-caption text-text-muted tabular-nums"
                data-test="count"
            >
                {{ labels.count(matched, total) }}
            </p>
        </div>

        <ListStates
            v-if="state === 'loading'"
            state="loading"
            :items="labels.items"
            :action="labels.action"
            :toolbar="false"
        />

        <p
            v-else-if="state === 'forbidden'"
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>

        <ListStates
            v-else-if="state === 'error'"
            state="error"
            :items="labels.items"
            :action="labels.action"
            @retry="load"
        />

        <ListStates
            v-else-if="isEmpty"
            state="empty"
            :items="labels.items"
            :action="labels.action"
        >
            <AddAttributeButton
                :expanded="adding"
                :controls="addId"
                @open="toggleAdd"
            />
        </ListStates>

        <section
            v-else-if="searching && matched === 0"
            data-slot="list-no-match"
            class="flex flex-col items-center gap-4 rounded-lg border border-border-default bg-surface-card p-6"
        >
            <p class="type-body-md text-center text-text-secondary">
                {{
                    t('list-no-match', {
                        items: labels.items,
                        query: appliedSearch.trim(),
                    })
                }}
            </p>
            <Button
                type="button"
                variant="secondary"
                data-test="clear-search"
                @click="clearSearch"
            >
                {{ labels.clearSearch }}
            </Button>
        </section>

        <DataTable
            v-else
            :caption="labels.caption"
            :label="labels.tableRegion"
            :columns="columns"
            :rows="keys"
            :row-key="(key: AttributeKey) => key.id"
            :busy="refreshing"
            :highlighted="highlighted"
            @row-blur="clearHighlight"
        >
            <template #cell-label="{ row }">
                <form
                    v-if="renaming === row.id"
                    class="flex max-w-md flex-col gap-2"
                    novalidate
                    :data-test="`rename-form-${row.key_id}`"
                    @submit.prevent="saveRename(row)"
                    @keydown.esc.prevent="cancelRename(row)"
                >
                    <FormField
                        :label="labels.renameField(row.key_id)"
                        :error="renameError"
                        #default="{ field }"
                    >
                        <Input
                            v-bind="field"
                            v-model="draft"
                            type="text"
                            maxlength="64"
                            autocomplete="off"
                            :data-rename-input="row.id"
                            data-test="rename-input"
                            @input="renameError = null"
                        />
                    </FormField>
                    <span class="flex flex-wrap items-center gap-2">
                        <Button
                            type="submit"
                            variant="secondary"
                            size="sm"
                            :disabled="renameSaving"
                            :aria-label="labels.renameSaveFor(row.label)"
                            data-test="rename-save"
                        >
                            {{ labels.renameSave }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test="rename-cancel"
                            @click="cancelRename(row)"
                        >
                            {{ labels.renameCancel }}
                        </Button>
                    </span>
                </form>
                <template v-else>{{ row.label }}</template>
            </template>
            <template #cell-value_type="{ row }">
                {{ labels.types[row.value_type] ?? row.value_type }}
            </template>
            <template #cell-actions="{ row }">
                <Button
                    v-if="renaming !== row.id"
                    type="button"
                    variant="secondary"
                    size="sm"
                    :aria-label="labels.renameFor(row.label)"
                    :data-rename-for="row.id"
                    data-test="rename"
                    @click="startRename(row)"
                >
                    {{ labels.rename }}
                </Button>
            </template>
        </DataTable>
    </div>
</template>
