<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { ROLE_TONES } from '@/lib/blockMapping';
import type { ChecklistSlot, MappingRole, RoleField } from '@/lib/blockMapping';
import type { PreviewResult } from '@/lib/blockPreview';
import { blockMapLabels, blockPreviewLabels as labels } from '@/locales/labels';

// "Where each part comes from" (Story 3.14), under the Block: a row per mapped slot with the field's role chip, the field (mono), "→" and what
// the card shows for it, then the sample note. The result is what the card shows in the slot's region (as the card formats it); a slot with no
// region shows the engine's value. A slot the server marked unavailable says so: it is never shown as 0. A role is never colour alone: its name
// is printed.
const props = defineProps<{
    slots: ChecklistSlot[];
    roles: RoleField[];
    result: PreviewResult | null;
    // What the card shows in each region, by slot.
    rendered: Record<string, string>;
    // "GET /api/v2/finance/revenue", or null when the Draft has none.
    endpoint: string | null;
}>();

const { t } = useI18n();
const titleId = useId();

const rows = computed(() => {
    const result = props.result;

    if (result === null) {
        return [];
    }

    return props.slots
        .filter((slot) => !!slot.path && !!slot.display)
        .map((slot) => {
            const roleName = props.roles.find(
                (field) => field.path === slot.path,
            )?.role;
            const role: MappingRole | null = roleName ?? null;
            const server = result.slots[slot.slot];
            const reason = server?.unavailable ?? result.unavailable[slot.slot];
            const shown = props.rendered[slot.slot];
            const text = reason
                ? labels.unavailableWhy(
                      labels.unavailableReasons[reason] ?? reason,
                  )
                : (shown ?? server?.value ?? null);

            return {
                slot: slot.slot,
                label: slot.label,
                field: slot.display as string,
                role,
                text: text ?? labels.unavailable,
                unavailable: !!reason || text === null,
            };
        });
});
</script>

<template>
    <section
        class="flex flex-col gap-2"
        :aria-labelledby="titleId"
        data-test="source-legend"
    >
        <h3 :id="titleId" class="type-label-caps text-text-muted">
            {{ labels.legendTitle }}
        </h3>
        <ul
            v-if="rows.length > 0"
            class="m-0 flex list-none flex-col gap-1.5 p-0"
        >
            <li
                v-for="row in rows"
                :key="row.slot"
                class="type-body-sm flex flex-wrap items-center gap-x-2 gap-y-1 text-text-secondary"
                data-test="legend-row"
                :data-slot="row.slot"
            >
                <span
                    v-if="row.role"
                    class="type-caption rounded-sm border px-1.5"
                    :class="ROLE_TONES[row.role]"
                    data-test="legend-role"
                    >{{ blockMapLabels.roleNames[row.role] ?? row.role }}</span
                >
                <span
                    class="font-mono text-text-primary"
                    data-test="legend-field"
                    >{{ row.field }}</span
                >
                <span aria-hidden="true">→</span>
                <span
                    :class="
                        row.unavailable ? 'text-warning' : 'text-text-primary'
                    "
                    data-test="legend-result"
                    >{{ row.text }}</span
                >
                <span class="type-caption text-text-muted"
                    >· {{ row.label }}</span
                >
            </li>
        </ul>
        <p v-else class="type-caption text-text-muted" data-test="legend-empty">
            {{ labels.legendEmpty }}
        </p>
        <p class="type-caption text-text-muted" data-test="sample-note">
            {{ t('sample-note', { endpoint: endpoint ?? labels.noEndpoint }) }}
        </p>
    </section>
</template>
