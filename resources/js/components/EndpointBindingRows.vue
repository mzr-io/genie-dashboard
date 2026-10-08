<script setup lang="ts">
import { computed } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { BINDINGS, RESOLVED_TEXT } from '@/lib/endpoints';
import type { Binding } from '@/lib/endpoints';
import { endpointLabels as labels } from '@/locales/labels';

// The rows of the Endpoint's parameters (`parameters-table`: Name in mono, Binding select, Value input, remove) and
// headers (the same columns). A bound row (a date range or period binding) has no value to type: it shows what it
// resolves to when fetched, in muted mono. The rows belong to the form; this component edits them in place and reports
// adding and removing. Errors are keyed `params.0.name`, and each input's id is `{idPrefix}-{field with dashes}`.
export type EditableRow = {
    key: number;
    name: string;
    binding: Binding;
    value: string;
    // Added for a `{placeholder}` of the path and not touched since: it goes when its placeholder does.
    auto?: boolean;
};

const props = defineProps<{
    kind: 'params' | 'headers';
    rows: EditableRow[];
    errors: Record<string, string | null>;
    idPrefix: string;
    caption: string;
    empty: string;
    addLabel: string;
}>();

const emit = defineEmits<{
    add: [];
    remove: [index: number];
    edited: [field: string];
}>();

const text = computed(() =>
    props.kind === 'params'
        ? {
              name: labels.paramName,
              binding: labels.paramBinding,
              value: labels.paramValue,
              remove: labels.removeParam,
          }
        : {
              name: labels.headerName,
              binding: labels.headerBinding,
              value: labels.headerValue,
              remove: labels.removeHeaderRow,
          },
);

const id = (index: number, field: string): string =>
    `${props.idPrefix}-${props.kind}-${index}-${field}`;

const errorOf = (index: number, field: string): string | null =>
    props.errors[`${props.kind}.${index}.${field}`] ?? null;

const describedBy = (index: number, field: string): string | undefined =>
    errorOf(index, field) ? `${id(index, field)}-error` : undefined;

const resolved = (binding: Binding): string =>
    binding === 'fixed' ? '' : RESOLVED_TEXT[binding];
</script>

<template>
    <div
        class="grid gap-3"
        :data-slot="kind === 'params' ? 'parameters-table' : 'header-rows'"
    >
        <p
            v-if="rows.length === 0"
            class="type-body-sm text-text-muted"
            :data-test="`${kind}-none`"
        >
            {{ empty }}
        </p>
        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-xl border-collapse text-left">
                <caption class="sr-only">
                    {{
                        caption
                    }}
                </caption>
                <thead>
                    <tr>
                        <th
                            scope="col"
                            class="type-caption pe-2 pb-1 font-semibold text-text-secondary"
                        >
                            {{ labels.nameColumn }}
                        </th>
                        <th
                            scope="col"
                            class="type-caption pe-2 pb-1 font-semibold text-text-secondary"
                        >
                            {{ labels.bindingColumn }}
                        </th>
                        <th
                            scope="col"
                            class="type-caption pe-2 pb-1 font-semibold text-text-secondary"
                        >
                            {{ labels.valueColumn }}
                        </th>
                        <th scope="col" class="pb-1">
                            <span class="sr-only">{{
                                labels.removeColumn
                            }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="row.key"
                        class="align-top"
                        :data-test="`${kind}-row`"
                    >
                        <td class="pe-2 pb-2">
                            <Input
                                :id="id(index, 'name')"
                                v-model="row.name"
                                type="text"
                                maxlength="128"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                                class="font-mono"
                                :aria-label="text.name(index + 1)"
                                :aria-invalid="
                                    errorOf(index, 'name') ? 'true' : undefined
                                "
                                :aria-describedby="describedBy(index, 'name')"
                                :data-test="`${kind}-name`"
                                @input="emit('edited', `${kind}.${index}.name`)"
                            />
                            <p
                                v-if="errorOf(index, 'name')"
                                :id="`${id(index, 'name')}-error`"
                                class="type-caption mt-1 text-error-text"
                                data-slot="field-error"
                            >
                                {{ errorOf(index, 'name') }}
                            </p>
                        </td>
                        <td class="pe-2 pb-2">
                            <NativeSelect
                                :id="id(index, 'binding')"
                                v-model="row.binding"
                                :aria-label="text.binding(index + 1)"
                                :aria-invalid="
                                    errorOf(index, 'binding')
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="
                                    describedBy(index, 'binding')
                                "
                                :data-test="`${kind}-binding`"
                                @change="
                                    emit('edited', `${kind}.${index}.binding`)
                                "
                            >
                                <option
                                    v-for="binding in BINDINGS"
                                    :key="binding"
                                    :value="binding"
                                >
                                    {{ labels.bindings[binding] }}
                                </option>
                            </NativeSelect>
                            <p
                                v-if="errorOf(index, 'binding')"
                                :id="`${id(index, 'binding')}-error`"
                                class="type-caption mt-1 text-error-text"
                                data-slot="field-error"
                            >
                                {{ errorOf(index, 'binding') }}
                            </p>
                        </td>
                        <td class="pe-2 pb-2">
                            <template v-if="row.binding === 'fixed'">
                                <Input
                                    :id="id(index, 'value')"
                                    v-model="row.value"
                                    type="text"
                                    maxlength="2048"
                                    autocomplete="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    :aria-label="text.value(index + 1)"
                                    :aria-invalid="
                                        errorOf(index, 'value')
                                            ? 'true'
                                            : undefined
                                    "
                                    :aria-describedby="
                                        describedBy(index, 'value')
                                    "
                                    :data-test="`${kind}-value`"
                                    @input="
                                        emit('edited', `${kind}.${index}.value`)
                                    "
                                />
                                <p
                                    v-if="errorOf(index, 'value')"
                                    :id="`${id(index, 'value')}-error`"
                                    class="type-caption mt-1 text-error-text"
                                    data-slot="field-error"
                                >
                                    {{ errorOf(index, 'value') }}
                                </p>
                            </template>
                            <span
                                v-else
                                class="type-caption inline-flex min-h-9 items-center font-mono text-text-muted"
                                :title="labels.boundHint(resolved(row.binding))"
                                data-test="resolved"
                            >
                                <span class="sr-only">{{
                                    labels.boundPrefix
                                }}</span>
                                {{ resolved(row.binding) }}
                            </span>
                        </td>
                        <td class="pb-2">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="text.remove(index + 1)"
                                :data-test="`${kind}-remove`"
                                @click="emit('remove', index)"
                            >
                                {{ labels.removeRow }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div>
            <Button
                :id="`${idPrefix}-${kind}-add`"
                type="button"
                variant="secondary"
                size="sm"
                :data-test="`${kind}-add`"
                @click="emit('add')"
            >
                {{ addLabel }}
            </Button>
        </div>
    </div>
</template>
