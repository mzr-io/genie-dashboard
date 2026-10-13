<script setup lang="ts">
import { computed, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import NativeSelect from '@/components/NativeSelect.vue';
import { BLOCK_ICONS, iconFor } from '@/lib/blockIcons';
import {
    BAND_COLORS,
    BAND_OPS,
    LIMITS,
    bandPath,
} from '@/lib/blockPresentation';
import type {
    BandColor,
    BandOp,
    PresentationBand,
} from '@/lib/blockPresentation';
import { blockPresentationLabels as labels } from '@/locales/labels';

// Threshold bands (Story 3.15b): "+ Add band" rows, the first match from the top wins. Each row: its order numeral, a condition (at least, at most,
// between with a second value), a colour (swatch and name; never accent), an icon, a label and Remove. A band lacking an icon or a label is
// refused here and by the server. Rows stack under 640px. Text is committed on change (blur or Enter), selects at once.
const props = defineProps<{
    bands: PresentationBand[];
    // Faults by `config.presentation.<path>`.
    errors: Record<string, string>;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    (e: 'update', bands: PresentationBand[]): void;
    (e: 'add'): void;
}>();

const id = useId();
const full = computed(() => props.bands.length >= LIMITS.bands);
const iconKeys = Object.keys(BLOCK_ICONS);

// A literal class per colour, so the stylesheet sees every one. The name is always printed beside it.
const SWATCH: Record<BandColor, string> = {
    success: 'bg-success',
    warning: 'bg-warning-bar',
    error: 'bg-error',
    info: 'bg-info',
    neutral: 'bg-text-muted',
};

function patch(index: number, change: Partial<PresentationBand>): void {
    emit(
        'update',
        props.bands.map((band, at) =>
            at === index ? { ...band, ...change } : band,
        ),
    );
}

function setOp(index: number, op: BandOp): void {
    const band = props.bands[index];
    const next: PresentationBand = { ...band, op };

    if (op !== 'between') {
        delete next.to;
    } else if (next.to === undefined) {
        next.to = '';
    }

    // A number and a word do not convert: the value is asked for again.
    if ((op === 'equals') !== (band.op === 'equals')) {
        next.value = '';
    }

    emit(
        'update',
        props.bands.map((entry, at) => (at === index ? next : entry)),
    );
}

function remove(index: number): void {
    emit(
        'update',
        props.bands.filter((_, at) => at !== index),
    );
}

const value = (event: Event): string =>
    (event.target as HTMLInputElement).value;
const error = (index: number, part: string): string | undefined =>
    props.errors[bandPath(index, part)];
const rowErrors = (index: number): { part: string; message: string }[] =>
    ['op', 'value', 'to', 'color', 'icon', 'label']
        .map((part) => ({ part, message: error(index, part) }))
        .filter(
            (entry): entry is { part: string; message: string } =>
                entry.message !== undefined,
        );
</script>

<template>
    <div class="grid gap-3" data-test="bands-editor">
        <div>
            <p class="type-title-sm text-text-primary">
                {{ labels.bandsTitle }}
            </p>
            <p class="type-caption text-text-muted">
                {{ labels.bandsCaption }}
            </p>
        </div>

        <p
            v-if="bands.length === 0"
            class="type-body-sm text-text-secondary"
            data-test="bands-none"
        >
            {{ labels.bandsNone }}
        </p>

        <ol class="m-0 grid list-none gap-3 p-0">
            <li
                v-for="(band, index) in bands"
                :key="index"
                class="bg-surface-page grid gap-2 rounded-md border border-border-default p-3 sm:grid-cols-[2rem_minmax(0,1fr)]"
                :aria-label="labels.bandName(index + 1)"
                role="group"
                data-test="band-row"
            >
                <span
                    class="type-title-sm grid size-8 place-content-center rounded-full bg-surface-muted text-text-primary tabular-nums"
                    data-test="band-order"
                    aria-hidden="true"
                    >{{ labels.bandOrder(index + 1) }}</span
                >

                <div class="grid min-w-0 gap-2">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <label
                                :for="`${id}-op-${index}`"
                                class="type-caption text-text-secondary"
                                >{{ labels.bandCondition }}</label
                            >
                            <NativeSelect
                                :id="`${id}-op-${index}`"
                                :model-value="band.op"
                                :disabled="disabled"
                                data-test="band-op"
                                @update:model-value="
                                    (v) => setOp(index, v as BandOp)
                                "
                            >
                                <option
                                    v-for="op in BAND_OPS"
                                    :key="op"
                                    :value="op"
                                >
                                    {{ labels.bandOps[op] }}
                                </option>
                            </NativeSelect>
                        </div>

                        <div
                            class="grid gap-2"
                            :class="band.op === 'between' ? 'grid-cols-2' : ''"
                        >
                            <div class="grid gap-1">
                                <label
                                    :for="`${id}-value-${index}`"
                                    class="type-caption text-text-secondary"
                                    >{{ labels.bandValue }}</label
                                >
                                <Input
                                    :id="`${id}-value-${index}`"
                                    :model-value="band.value"
                                    :inputmode="
                                        band.op === 'equals'
                                            ? 'text'
                                            : 'decimal'
                                    "
                                    autocomplete="off"
                                    :disabled="disabled"
                                    :aria-invalid="
                                        error(index, 'value')
                                            ? 'true'
                                            : undefined
                                    "
                                    data-test="band-value"
                                    @change="
                                        (e: Event) =>
                                            patch(index, {
                                                value: value(e).trim(),
                                            })
                                    "
                                />
                                <p
                                    v-if="band.op === 'equals'"
                                    class="type-caption text-text-muted"
                                    data-test="band-word-hint"
                                >
                                    {{ labels.bandWordHint }}
                                </p>
                            </div>
                            <div
                                v-if="band.op === 'between'"
                                class="grid gap-1"
                            >
                                <label
                                    :for="`${id}-to-${index}`"
                                    class="type-caption text-text-secondary"
                                    >{{ labels.bandTo }}</label
                                >
                                <Input
                                    :id="`${id}-to-${index}`"
                                    :model-value="band.to ?? ''"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    :disabled="disabled"
                                    :aria-invalid="
                                        error(index, 'to') ? 'true' : undefined
                                    "
                                    data-test="band-to"
                                    @change="
                                        (e: Event) =>
                                            patch(index, {
                                                to: value(e).trim(),
                                            })
                                    "
                                />
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <div class="grid gap-1">
                            <label
                                :for="`${id}-color-${index}`"
                                class="type-caption text-text-secondary"
                                >{{ labels.bandColor }}</label
                            >
                            <div class="flex items-center gap-2">
                                <span
                                    class="size-4 shrink-0 rounded-full border border-border-control"
                                    :class="
                                        SWATCH[band.color] ?? 'bg-text-muted'
                                    "
                                    aria-hidden="true"
                                    data-test="band-swatch"
                                />
                                <NativeSelect
                                    :id="`${id}-color-${index}`"
                                    :model-value="band.color"
                                    :disabled="disabled"
                                    data-test="band-color"
                                    @update:model-value="
                                        (v) =>
                                            patch(index, {
                                                color: v as BandColor,
                                            })
                                    "
                                >
                                    <option
                                        v-for="color in BAND_COLORS"
                                        :key="color"
                                        :value="color"
                                    >
                                        {{ labels.colors[color] }}
                                    </option>
                                </NativeSelect>
                            </div>
                        </div>

                        <div class="grid gap-1">
                            <label
                                :for="`${id}-icon-${index}`"
                                class="type-caption text-text-secondary"
                                >{{ labels.bandIcon }}</label
                            >
                            <div class="flex items-center gap-2">
                                <component
                                    :is="iconFor(band.icon)"
                                    v-if="band.icon"
                                    class="size-4 shrink-0 text-text-secondary"
                                    aria-hidden="true"
                                />
                                <NativeSelect
                                    :id="`${id}-icon-${index}`"
                                    :model-value="band.icon"
                                    :disabled="disabled"
                                    :aria-invalid="
                                        error(index, 'icon')
                                            ? 'true'
                                            : undefined
                                    "
                                    data-test="band-icon"
                                    @update:model-value="
                                        (v) => patch(index, { icon: v ?? '' })
                                    "
                                >
                                    <option value="">
                                        {{ labels.bandIconNone }}
                                    </option>
                                    <option
                                        v-for="key in iconKeys"
                                        :key="key"
                                        :value="key"
                                    >
                                        {{ BLOCK_ICONS[key].label }}
                                    </option>
                                </NativeSelect>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-1">
                        <label
                            :for="`${id}-label-${index}`"
                            class="type-caption text-text-secondary"
                            >{{ labels.bandLabel }}</label
                        >
                        <Input
                            :id="`${id}-label-${index}`"
                            :model-value="band.label"
                            autocomplete="off"
                            :disabled="disabled"
                            :aria-invalid="
                                error(index, 'label') ? 'true' : undefined
                            "
                            data-test="band-label"
                            @change="
                                (e: Event) => patch(index, { label: value(e) })
                            "
                        />
                    </div>

                    <ul
                        v-if="rowErrors(index).length > 0"
                        class="m-0 list-none p-0"
                        data-test="band-errors"
                    >
                        <li
                            v-for="entry in rowErrors(index)"
                            :key="entry.part"
                            class="type-caption text-error-text"
                        >
                            {{ entry.message }}
                        </li>
                    </ul>

                    <div v-if="!disabled">
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            :aria-label="labels.removeBand(index + 1)"
                            data-test="band-remove"
                            @click="remove(index)"
                            >{{ labels.removeBand(index + 1) }}</Button
                        >
                    </div>
                </div>
            </li>
        </ol>

        <p
            v-if="errors['config.presentation.bands']"
            class="type-caption text-error-text"
            data-test="bands-error"
        >
            {{ errors['config.presentation.bands'] }}
        </p>

        <div v-if="!disabled">
            <Button
                type="button"
                variant="secondary"
                size="sm"
                :blocked="full"
                data-test="band-add"
                @click="emit('add')"
                >{{ labels.addBand }}</Button
            >
        </div>
    </div>
</template>
