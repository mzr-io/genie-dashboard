<script setup lang="ts">
import { computed, useId } from 'vue';
import BlockedReason from '@/components/BlockedReason.vue';
import Tag from '@/components/Tag.vue';
import { Button } from '@/components/ui/button';
import RecordPathList from '@/components/wizard/RecordPathList.vue';
import RoleChip from '@/components/wizard/RoleChip.vue';
import {
    ROLE_TONES,
    flaggedFields,
    qualifierOf,
    rolesTable,
} from '@/lib/blockMapping';
import type {
    MappingRole,
    RoleField,
    RoleUndo,
    RolesReport,
} from '@/lib/blockMapping';
import type { StoredSample } from '@/lib/blockSample';
import { blockMapLabels as labels } from '@/locales/labels';

// The Roles section of Map Data (Story 3.11b; UX-DR-65, 66, 123 to 129): what each field means. Expanded it shows the record-path choice (when the
// response has several arrays), the suspected-role suggestions, a `scalar-card` per single value (at most 4 to a row, one per row on a small
// screen) and a `roles-table` of at most 5 sample rows with a role chip above each column. Collapsed (roles accepted) it is the `roles-summary-line`
// with "Edit roles". It holds no state of its own: the step decides what is open and writes the changes.
const props = defineProps<{
    roles: RolesReport;
    sample: StoredSample | null;
    expanded: boolean;
    dismissed: ReadonlySet<string>;
    // Why nothing can be changed now (read-only, offline); undefined when it can.
    blocked?: string;
    // The role change the Admin may still undo.
    undo: RoleUndo | null;
}>();

const emit = defineEmits<{
    (e: 'role', field: RoleField, role: MappingRole): void;
    (e: 'suggest', field: RoleField): void;
    (e: 'keep', field: RoleField): void;
    (e: 'looks-right'): void;
    (e: 'edit'): void;
    (e: 'path', path: string): void;
    (e: 'undo'): void;
}>();

const reasonId = useId();
const tableHeadingId = useId();
const disabled = computed(() => props.blocked !== undefined);
const table = computed(() => rolesTable(props.sample, props.roles));
// A field in the records has its chip above its column; one without a column in the sample's rows (no rows, or a column the sample lacks)
// gets a card like any other, so its chip is always reachable.
const cards = computed(() =>
    props.roles.fields.filter(
        (field) =>
            !field.in_records ||
            !table.value?.columns.some((c) => c.field?.path === field.path),
    ),
);
const scalars = computed(() =>
    props.roles.fields.filter((field) => !field.in_records),
);
const flagged = computed(() =>
    flaggedFields(props.roles.fields, props.dismissed),
);
const flaggedPaths = computed(
    () => new Set(flagged.value.map((field) => field.path)),
);
const name = (role: string): string => labels.roleNames[role] ?? role;

function qualifier(field: RoleField): string | null {
    const key = qualifierOf(field, scalars.value);

    return key === null ? null : labels.roleQualifiers[key];
}
</script>

<template>
    <div class="grid gap-4" data-test="roles">
        <BlockedReason
            v-if="blocked"
            :id="reasonId"
            data-test="roles-blocked"
            >{{ blocked }}</BlockedReason
        >

        <RecordPathList
            v-if="roles.needs_choice"
            :candidates="roles.candidates"
            :value="roles.record_path"
            :disabled="disabled"
            :reason-id="reasonId"
            @change="(path) => emit('path', path)"
        />

        <div
            v-if="!expanded"
            class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-border-default bg-surface-sunken px-3 py-2"
            data-test="roles-summary-line"
        >
            <span class="type-body-sm text-text-secondary">{{
                labels.summaryTitle
            }}</span>
            <ul
                class="m-0 flex min-w-0 list-none flex-wrap items-center gap-x-3 gap-y-1 p-0"
            >
                <li
                    v-for="field in roles.fields"
                    :key="field.path"
                    class="type-body-sm flex items-center gap-1 text-text-secondary"
                    data-test="summary-field"
                >
                    <span class="font-mono">{{ field.display }}</span>
                    <span
                        class="type-caption rounded-sm border px-2"
                        :class="ROLE_TONES[field.role]"
                        >{{ name(field.role) }}</span
                    >
                </li>
            </ul>
            <p
                v-if="roles.fields.length === 0"
                class="type-body-sm text-text-secondary"
            >
                {{ labels.summaryNone }}
            </p>
            <Button
                type="button"
                variant="link"
                data-test="edit-roles"
                @click="emit('edit')"
                >{{ labels.editRoles }}</Button
            >
        </div>

        <template v-else>
            <ul
                v-if="flagged.length > 0"
                class="m-0 grid list-none gap-2 p-0"
                data-test="suggestions"
            >
                <li
                    v-for="field in flagged"
                    :key="field.path"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-accent-border bg-accent-wash px-3 py-2"
                    data-test="suggestion-row"
                    :data-path="field.path"
                >
                    <span class="type-body-sm min-w-0 text-text-primary">{{
                        labels.suggestion(
                            field.display,
                            name(field.suggestion?.role ?? 'measure'),
                        )
                    }}</span>
                    <span class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            :blocked="disabled"
                            :blocked-reason="blocked"
                            :aria-describedby="disabled ? reasonId : undefined"
                            data-test="suggestion-accept"
                            @click="emit('suggest', field)"
                            >{{
                                labels.setAs(
                                    name(field.suggestion?.role ?? 'measure'),
                                )
                            }}</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            :blocked="disabled"
                            :blocked-reason="blocked"
                            :aria-describedby="disabled ? reasonId : undefined"
                            data-test="suggestion-keep"
                            @click="emit('keep', field)"
                            >{{ labels.keepAs(name(field.role)) }}</Button
                        >
                    </span>
                </li>
            </ul>

            <p
                v-if="undo"
                class="type-body-sm flex flex-wrap items-center gap-2 text-text-secondary"
                data-test="role-undo"
            >
                <span>{{ labels.undoNote(undo.field, name(undo.now)) }}</span>
                <Button
                    type="button"
                    variant="link"
                    :blocked="disabled"
                    :blocked-reason="blocked"
                    data-test="undo-role"
                    @click="emit('undo')"
                    >{{ labels.undo }}</Button
                >
            </p>

            <section
                v-if="cards.length > 0"
                :aria-label="labels.scalarList"
                class="grid gap-2"
            >
                <h3 class="type-caption font-semibold text-text-secondary">
                    {{ labels.scalarsTitle }}
                </h3>
                <ul
                    class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2 xl:grid-cols-4"
                    data-test="scalar-cards"
                >
                    <li
                        v-for="field in cards"
                        :key="field.path"
                        class="flex min-w-0 flex-col gap-2 rounded-md border border-border-default bg-surface-card p-3"
                        data-test="scalar-card"
                        :data-path="field.path"
                    >
                        <span
                            class="type-body-sm font-mono break-all text-text-primary"
                            data-test="scalar-key"
                            :title="field.path"
                            >{{ field.display }}</span
                        >
                        <Tag data-test="scalar-type">{{ field.type }}</Tag>
                        <span
                            class="type-caption font-mono break-all text-text-muted"
                            data-test="scalar-example"
                            >{{ field.example ?? labels.exampleNone }}</span
                        >
                        <span class="flex flex-wrap items-center gap-1">
                            <RoleChip
                                :field="field.display"
                                :path="field.path"
                                :role="field.role"
                                :flagged="flaggedPaths.has(field.path)"
                                :disabled="disabled"
                                :reason-id="reasonId"
                                @change="(role) => emit('role', field, role)"
                            />
                            <span
                                v-if="qualifier(field)"
                                class="type-caption text-text-secondary"
                                data-test="scalar-qualifier"
                                >· {{ qualifier(field) }}</span
                            >
                        </span>
                    </li>
                </ul>
            </section>

            <section v-if="table" class="grid gap-2" data-test="roles-table">
                <h3
                    :id="tableHeadingId"
                    class="type-caption font-semibold text-text-secondary"
                >
                    {{ labels.tableHeading }}
                </h3>
                <div
                    role="region"
                    tabindex="0"
                    :aria-label="labels.tableRegion"
                    class="overflow-x-auto rounded-md border border-border-default"
                    data-test="roles-table-region"
                >
                    <table class="w-full border-collapse text-left">
                        <caption class="sr-only">
                            {{
                                labels.tableCaption(table.path)
                            }}
                        </caption>
                        <thead>
                            <tr>
                                <th
                                    v-for="column in table.columns"
                                    :key="column.key"
                                    scope="col"
                                    class="type-caption px-3 pt-2 pb-1 align-bottom font-normal"
                                >
                                    <span class="grid gap-1">
                                        <RoleChip
                                            v-if="column.field"
                                            :field="column.display"
                                            :path="column.field.path"
                                            :role="column.field.role"
                                            :flagged="
                                                flaggedPaths.has(
                                                    column.field.path,
                                                )
                                            "
                                            :disabled="disabled"
                                            :reason-id="reasonId"
                                            @change="
                                                (role) =>
                                                    emit(
                                                        'role',
                                                        column.field!,
                                                        role,
                                                    )
                                            "
                                        />
                                        <span
                                            class="font-mono font-semibold text-text-secondary"
                                            >{{ column.key }}</span
                                        >
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in table.rows"
                                :key="index"
                                data-test="roles-row"
                            >
                                <td
                                    v-for="(cell, at) in row"
                                    :key="at"
                                    class="type-body-sm px-3 py-1 font-mono break-all text-text-primary"
                                >
                                    {{ cell }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p
                v-if="roles.fields.length === 0"
                class="type-body-sm text-text-secondary"
                data-test="no-fields"
            >
                {{ labels.noFields }}
            </p>

            <div v-else class="flex justify-end">
                <Button
                    type="button"
                    variant="secondary"
                    :blocked="disabled"
                    :blocked-reason="blocked"
                    :aria-describedby="disabled ? reasonId : undefined"
                    data-test="looks-right"
                    @click="emit('looks-right')"
                    >{{ labels.looksRight }}</Button
                >
            </div>
        </template>
    </div>
</template>
