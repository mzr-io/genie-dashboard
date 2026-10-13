<script setup lang="ts">
import { FileText } from '@lucide/vue';
import { nextTick, ref, useId } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FIELD_FORMATS, TEMPLATE_MAX } from '@/lib/blockTemplates';
import type { TemplateSources } from '@/lib/blockTemplates';
import { shortName } from '@/lib/blockTransforms';
import { blockTemplateLabels as labels } from '@/locales/labels';

// "Text templates" of Map Data (Story 5.4; UX-DR-193, 196): one editor per text slot that has a template, with a token helper. A template is saved when the
// field is left or Enter is pressed; the server judges the grammar and the fields and names a refused token with its position, shown on the row. The
// live preview beside it is the real card, so the result is what the viewer sees (numbers and dates in their language, `{user.name}` their own name).
const props = defineProps<{
    // The templates as the Admin has them, by slot (an empty one is a template just chosen, not yet saved).
    templates: TemplateSources;
    // The text slots, to name a row, and the fields a token can name.
    slots: { slot: string; label: string }[];
    fields: { path: string; display: string }[];
    // What the server (or the client) says is wrong with a slot's template.
    errors: Record<string, string>;
    // Why nothing can be changed now (read-only, offline, published).
    blocked?: string;
}>();

const emit = defineEmits<{
    change: [slot: string, source: string];
    remove: [slot: string];
    // Escape in a template the picker opened: the page puts focus back where it came from.
    cancel: [slot: string];
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const uid = useId();
const disabled = (): boolean => props.blocked !== undefined;

const labelOf = (slot: string): string =>
    props.slots.find((s) => s.slot === slot)?.label ?? slot;
const rows = (): string[] => Object.keys(props.templates);

function inputOf(slot: string): HTMLInputElement | null {
    return (
        Array.from(
            root.value?.querySelectorAll<HTMLInputElement>(
                '[data-test="template-input"]',
            ) ?? [],
        ).find((el) => el.dataset.slot === slot) ?? null
    );
}

function commit(slot: string, event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    if (value !== props.templates[slot]) {
        emit('change', slot, value);
    }
}

// A token goes in at the caret (or the end), and the template is saved with it.
function insert(slot: string, token: string): void {
    const el = inputOf(slot);

    if (el === null || disabled()) {
        return;
    }

    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? el.value.length;
    const next = el.value.slice(0, start) + token + el.value.slice(end);

    el.value = next;
    el.focus();
    el.setSelectionRange(start + token.length, start + token.length);
    emit('change', slot, next);
}

const field = ref<Record<string, string>>({});
const format = ref<Record<string, string>>({});

function insertField(slot: string): void {
    const path = field.value[slot];

    if (!path) {
        return;
    }

    const found = props.fields.find((f) => f.path === path);
    const name = shortName(found?.display ?? path);
    const fmt = format.value[slot];

    insert(slot, fmt ? `{${name}|${fmt}}` : `{${name}}`);
}

function focusSlot(slot: string): void {
    open.value = true;
    void nextTick(() => inputOf(slot)?.focus());
}

function focusFirstFault(): boolean {
    const slot = Object.keys(props.errors)[0];

    if (slot === undefined) {
        return false;
    }

    focusSlot(slot);

    return true;
}

defineExpose({ focusSlot, focusFirstFault, open });
</script>

<template>
    <details
        v-if="rows().length > 0"
        ref="root"
        class="rounded-lg border border-border-default bg-surface-card p-4"
        data-test="templates-section"
        :open="open"
        @toggle="(e: Event) => (open = (e.target as HTMLDetailsElement).open)"
    >
        <summary
            class="type-title-sm flex cursor-pointer items-center gap-3 text-text-primary"
        >
            <FileText class="size-4" aria-hidden="true" />
            {{ labels.title }}
            <span class="type-caption text-text-secondary"
                >({{ rows().length }})</span
            >
        </summary>
        <p class="type-caption mt-2 text-text-muted">{{ labels.caption }}</p>

        <ul class="m-0 mt-3 grid list-none gap-3 p-0">
            <li
                v-for="slot in rows()"
                :key="slot"
                class="grid gap-2 rounded-md border border-border-default p-3"
                :class="
                    errors[slot] !== undefined
                        ? 'border-l-[3px] border-l-error bg-error-soft'
                        : 'bg-surface-card'
                "
                data-test="template-row"
                :data-slot-key="slot"
            >
                <label
                    :for="`${uid}-${slot}`"
                    class="type-label text-text-primary"
                    >{{ labels.slotName(labelOf(slot)) }}</label
                >
                <Input
                    :id="`${uid}-${slot}`"
                    class="font-mono"
                    type="text"
                    :maxlength="TEMPLATE_MAX"
                    autocomplete="off"
                    spellcheck="false"
                    :default-value="templates[slot]"
                    :placeholder="labels.placeholder"
                    :aria-label="labels.inputName(labelOf(slot))"
                    :aria-describedby="`${uid}-${slot}-help`"
                    :aria-invalid="
                        errors[slot] !== undefined ? 'true' : undefined
                    "
                    :disabled="disabled()"
                    data-test="template-input"
                    :data-slot="slot"
                    @change="commit(slot, $event)"
                    @keydown.enter="commit(slot, $event)"
                    @keydown.esc="emit('cancel', slot)"
                />
                <p
                    :id="`${uid}-${slot}-help`"
                    class="type-caption text-text-muted"
                >
                    {{ labels.inputHelp }}
                </p>

                <div
                    class="flex flex-wrap items-center gap-2"
                    role="group"
                    :aria-label="labels.helper"
                >
                    <NativeSelect
                        class="w-auto min-w-28"
                        :model-value="field[slot] ?? ''"
                        :aria-label="labels.insertFieldName"
                        :disabled="disabled()"
                        data-test="token-field"
                        @update:model-value="
                            (v?: string) => (field[slot] = v ?? '')
                        "
                    >
                        <option value="">{{ labels.insertField }}</option>
                        <option
                            v-for="f in fields"
                            :key="f.path"
                            :value="f.path"
                        >
                            {{ shortName(f.display) }}
                        </option>
                    </NativeSelect>
                    <NativeSelect
                        class="w-auto min-w-28"
                        :model-value="format[slot] ?? ''"
                        :aria-label="labels.insertFormatName"
                        :disabled="disabled()"
                        data-test="token-format"
                        @update:model-value="
                            (v?: string) => (format[slot] = v ?? '')
                        "
                    >
                        <option value="">{{ labels.noFormat }}</option>
                        <option v-for="f in FIELD_FORMATS" :key="f" :value="f">
                            {{ f }}
                        </option>
                    </NativeSelect>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :aria-disabled="
                            disabled() || !field[slot] ? 'true' : undefined
                        "
                        data-test="token-insert-field"
                        @click="insertField(slot)"
                    >
                        {{ labels.insertField }}
                    </Button>
                    <Button
                        v-for="(token, key) in {
                            rowCount: labels.tokens.rowCount,
                            periodStart: labels.tokens.periodStart,
                            periodEnd: labels.tokens.periodEnd,
                            userName: labels.tokens.userName,
                        }"
                        :key="key"
                        type="button"
                        variant="secondary"
                        size="sm"
                        :aria-label="labels.tokenNames[key]"
                        :aria-disabled="disabled() ? 'true' : undefined"
                        :data-test="`token-${key}`"
                        @click="insert(slot, token)"
                    >
                        {{ token }}
                    </Button>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="type-caption text-text-secondary">
                        {{ labels.previewNote }}
                    </p>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :aria-label="labels.removeName(labelOf(slot))"
                        :aria-disabled="disabled() ? 'true' : undefined"
                        data-test="template-remove"
                        @click="!disabled() && emit('remove', slot)"
                    >
                        {{ labels.remove }}
                    </Button>
                </div>

                <p
                    v-if="errors[slot] !== undefined"
                    class="type-caption text-error-text"
                    role="alert"
                    data-test="template-error"
                >
                    {{ errors[slot] }}
                </p>
            </li>
        </ul>
    </details>
</template>
