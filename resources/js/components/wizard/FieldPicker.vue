<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import ListOption from '@/components/ListOption.vue';
import { Button } from '@/components/ui/button';
import { announce, announceDebounced } from '@/lib/announce';
import { filterFields, pathDepth } from '@/lib/blockMapping';
import type { PickerField } from '@/lib/blockMapping';
import { blockMapLabels as labels } from '@/locales/labels';

// The field picker of a slot row (Story 3.13b): "Change field ⌄" opens a 320px popover with a search box and a listbox of the fields the slot
// could take, compatible first. Focus stays in the search box and the highlighted option is `aria-activedescendant`; arrows, Home and End move,
// Enter picks, Escape closes and returns focus to the opener. A field that does not fit is `aria-disabled`, with its reason shown as a muted
// caption that the option points to (`aria-describedby`); Enter on it does nothing. Typing narrows the list ("n fields match", said politely).
const props = defineProps<{
    slotKey: string;
    slotLabel: string;
    // The path now mapped (the current option).
    current?: string | null;
    // Lists the slot's fields; called each time the popover opens, so it is never stale.
    load: () => Promise<PickerField[]>;
    // A write is in flight: a pick waits.
    busy?: boolean;
}>();

const emit = defineEmits<{ (e: 'pick', field: PickerField): void }>();

const open = ref(false);
const status = ref<'loading' | 'ready' | 'error'>('loading');
const fields = ref<PickerField[]>([]);
const query = ref('');
const active = ref(0);
const root = ref<HTMLElement | null>(null);
const opener = ref<InstanceType<typeof Button> | null>(null);
const search = ref<HTMLInputElement | null>(null);
const popoverId = useId();
const listId = useId();
const optionPrefix = useId();
let ticket = 0;

const shown = computed(() => filterFields(fields.value, query.value));
const activeId = computed(() =>
    shown.value[active.value] !== undefined
        ? `${optionPrefix}-${active.value}`
        : undefined,
);

function openerEl(): HTMLElement | null {
    return (opener.value?.$el as HTMLElement | undefined) ?? null;
}

async function read(): Promise<void> {
    const mine = (ticket += 1);

    status.value = 'loading';

    try {
        const loaded = await props.load();

        if (mine !== ticket) {
            return;
        }

        fields.value = loaded;
        status.value = 'ready';
        placeActive();
        announce(labels.fieldsMatch(shown.value.length), 'polite');
    } catch {
        if (mine === ticket) {
            status.value = 'error';
        }
    }
}

// The current field is the active option; with none, the first one.
function placeActive(): void {
    const at = shown.value.findIndex(
        (field) => field.path !== null && field.path === props.current,
    );

    active.value = at === -1 ? 0 : at;
}

async function show(prefill = ''): Promise<void> {
    open.value = true;
    query.value = prefill;
    void read();
    await nextTick();
    search.value?.focus();
}

function close(returnFocus: boolean): void {
    ticket += 1;
    open.value = false;

    if (returnFocus) {
        openerEl()?.focus();
    }
}

function toggle(): void {
    if (open.value) {
        close(false);
    } else {
        void show();
    }
}

watch(query, () => {
    active.value = 0;

    if (status.value === 'ready') {
        announceDebounced(
            'field-picker',
            labels.fieldsMatch(shown.value.length),
        );
    }
});

function scrollActive(): void {
    void nextTick(() =>
        document
            .getElementById(activeId.value ?? '')
            ?.scrollIntoView?.({ block: 'nearest' }),
    );
}

function pick(field: PickerField): void {
    // A field that does not fit says why, and nothing is sent.
    if (!field.compatible) {
        if (field.reason) {
            announce(field.reason, 'polite');
        }

        return;
    }

    if (props.busy === true) {
        return;
    }

    close(false);
    emit('pick', field);
}

function onKey(event: KeyboardEvent): void {
    const last = shown.value.length - 1;
    const keys = ['ArrowDown', 'ArrowUp', 'Home', 'End', 'Enter'];

    // Nothing to move over (still loading, failed, or no match): the list keys do nothing.
    if (keys.includes(event.key) && (status.value !== 'ready' || last < 0)) {
        event.preventDefault();

        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(last, active.value + 1);
        scrollActive();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(0, active.value - 1);
        scrollActive();
    } else if (event.key === 'Home') {
        event.preventDefault();
        active.value = 0;
        scrollActive();
    } else if (event.key === 'End') {
        event.preventDefault();
        active.value = Math.max(0, last);
        scrollActive();
    } else if (event.key === 'Enter') {
        event.preventDefault();

        const field = shown.value[active.value];

        if (field !== undefined) {
            pick(field);
        }
    }
}

// Focus leaving the popover and its opener closes it (Tab never gets trapped in it).
function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;

    if (open.value && next !== null && !root.value?.contains(next)) {
        close(false);
    }
}

// The window lost focus (no element takes it): the popover goes too.
function onWindowBlur(): void {
    if (open.value) {
        close(false);
    }
}

// The current field moved (a pick landed, a re-read): the active option follows it.
watch(
    () => props.current,
    () => {
        if (open.value && status.value === 'ready') {
            placeActive();
        }
    },
);

function onOutside(event: Event): void {
    if (open.value && !root.value?.contains(event.target as Node)) {
        close(false);
    }
}

onMounted(() => {
    document.addEventListener('pointerdown', onOutside);
    window.addEventListener('blur', onWindowBlur);
});
onBeforeUnmount(() => {
    ticket += 1;
    document.removeEventListener('pointerdown', onOutside);
    window.removeEventListener('blur', onWindowBlur);
});

defineExpose({ show, close, focus: () => openerEl()?.focus() });

const reasonId = (index: number): string => `${optionPrefix}-reason-${index}`;
</script>

<template>
    <div
        ref="root"
        class="relative w-fit"
        data-test="field-picker"
        @focusout="onFocusOut"
    >
        <Button
            ref="opener"
            type="button"
            variant="secondary"
            size="sm"
            aria-haspopup="listbox"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="open ? popoverId : undefined"
            :aria-label="labels.changeFieldFor(slotLabel)"
            data-test="change-field"
            @click="toggle"
        >
            {{ labels.changeField }}
            <ChevronDown class="size-3" aria-hidden="true" />
        </Button>

        <div
            v-if="open"
            :id="popoverId"
            role="dialog"
            :aria-label="labels.pickerLabel(slotLabel)"
            class="absolute start-0 z-20 mt-1 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2 rounded-lg border border-border-default bg-surface-card p-2 shadow-lg"
            data-test="picker-popover"
            @keydown.esc.stop.prevent="close(true)"
        >
            <input
                ref="search"
                v-model="query"
                type="search"
                role="combobox"
                aria-autocomplete="list"
                aria-expanded="true"
                :aria-controls="listId"
                :aria-activedescendant="activeId"
                :aria-label="labels.pickerSearch"
                :placeholder="labels.pickerSearchHint"
                autocomplete="off"
                class="type-body-sm min-h-9 w-full rounded-md border border-border-control bg-surface-card px-3 text-text-primary placeholder:text-text-muted focus:border-brand focus:outline-2 focus:outline-offset-2 focus:outline-(--df-focus-ring)"
                data-test="picker-search"
                @keydown="onKey"
            />

            <p
                v-if="status === 'loading'"
                class="type-body-sm px-1 text-text-secondary"
                role="status"
                aria-busy="true"
                data-test="picker-loading"
            >
                {{ labels.pickerLoading }}
            </p>

            <div
                v-else-if="status === 'error'"
                class="flex flex-col items-start gap-2 px-1"
                data-test="picker-failed"
            >
                <p class="type-body-sm text-error-text" role="alert">
                    {{ labels.pickerFailed }}
                </p>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="picker-retry"
                    @click="read"
                    >{{ labels.retry }}</Button
                >
            </div>

            <template v-else>
                <p
                    v-if="shown.length === 0"
                    class="type-body-sm px-1 text-text-secondary"
                    data-test="picker-empty"
                >
                    {{ labels.pickerEmpty }}
                </p>
                <div
                    :id="listId"
                    role="listbox"
                    :aria-label="labels.pickerLabel(slotLabel)"
                    class="flex max-h-64 flex-col gap-0.5 overflow-y-auto"
                    data-test="picker-list"
                >
                    <ListOption
                        v-for="(field, index) in shown"
                        :id="`${optionPrefix}-${index}`"
                        :key="field.path ?? field.kind"
                        :active="index === active"
                        :selected="
                            field.path !== null && field.path === current
                        "
                        :aria-disabled="field.compatible ? undefined : 'true'"
                        :aria-describedby="
                            field.compatible ? undefined : reasonId(index)
                        "
                        :class="[
                            'flex-wrap',
                            field.compatible ? '' : 'text-text-muted',
                        ]"
                        :data-path="field.path ?? undefined"
                        :data-compatible="field.compatible ? 'true' : 'false'"
                        data-test="picker-option"
                        @click="pick(field)"
                        @mousemove="active = index"
                    >
                        <span
                            class="flex items-stretch"
                            aria-hidden="true"
                            data-test="indent-guides"
                        >
                            <span
                                v-for="level in pathDepth(field.display)"
                                :key="level"
                                class="w-3 border-s border-border-default"
                            />
                        </span>
                        <span class="min-w-0 flex-1 truncate font-medium">{{
                            field.display
                        }}</span>
                        <span
                            class="type-caption shrink-0 rounded-sm border border-border-default bg-surface-muted px-1.5 text-text-secondary"
                            data-test="picker-type"
                            >{{ field.effective_type ?? field.type }}</span
                        >
                        <span
                            v-if="field.example"
                            class="type-caption w-full truncate ps-1 font-mono text-text-muted"
                            data-test="picker-example"
                            >{{ labels.exampleShort(field.example) }}</span
                        >
                        <span
                            v-if="!field.compatible && field.reason"
                            :id="reasonId(index)"
                            class="type-caption w-full text-text-muted"
                            data-test="picker-reason"
                            >{{ field.reason }}</span
                        >
                    </ListOption>
                </div>
            </template>
        </div>
    </div>
</template>
