<script setup lang="ts">
import { CalendarClock, LayoutTemplate, SlidersHorizontal } from '@lucide/vue';
import { computed } from 'vue';
import FormField from '@/components/FormField.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import IconPicker from '@/components/wizard/IconPicker.vue';
import SectionCard from '@/components/wizard/SectionCard.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    BLOCK_TYPES,
    DESCRIPTION_MAX,
    HEIGHTS,
    NAME_MAX,
    blockTypeFor,
    categoryIcon,
    heightLabel,
    heightName,
} from '@/lib/blockWizard';
import { announce } from '@/lib/announce';
import { FIELD_IDS } from '@/lib/blockWizard';
import type { BlockCategory, StepForm } from '@/lib/blockWizard';
import { blockWizardLabels as labels } from '@/locales/labels';

// Step 1, Configure Block (Story 3.4): basic information, display & behavior and period behavior, each in a section card. The form state
// belongs to the page; this component edits it in place and shows the errors it is given, keyed by field ("name", "display.width").
const props = defineProps<{
    form: StepForm;
    errors: Record<string, string | undefined>;
    categories: BlockCategory[];
}>();

const emit = defineEmits<{
    (e: 'change'): void;
    (e: 'blur', field: string): void;
}>();

const type = computed(() => blockTypeFor(props.form.type) ?? BLOCK_TYPES[0]);
const category = computed(
    () => props.categories.find((c) => c.id === props.form.categoryId) ?? null,
);
const categoryName = computed(() => category.value?.name ?? '');

const toggles = [
    ['show_header', labels.showHeader, 'wizard-show-header'],
    ['show_subtitle', labels.showSubtitle, 'wizard-show-subtitle'],
    ['allow_resize', labels.allowResize, 'wizard-allow-resize'],
    ['allow_minimize', labels.allowMinimize, 'wizard-allow-minimize'],
    ['allow_refresh', labels.allowRefresh, 'wizard-allow-refresh'],
] as const;

function setNumber(
    key: 'width' | 'min_width' | 'max_width',
    value: string | number,
): void {
    // A blank or non-numeric entry becomes NaN, which the width rule refuses with the allowed range.
    props.form.display[key] = value === '' ? Number.NaN : Number(value);
    emit('change');
}

const typeLabel = (key: string) =>
    labels.typeNames[key] ?? blockTypeFor(key)?.title ?? key;
</script>

<template>
    <div class="flex flex-col gap-4" data-slot="configure-block-step">
        <SectionCard
            :title="labels.sections.basics.title"
            :caption="labels.sections.basics.caption"
            :icon="LayoutTemplate"
        >
            <FormField
                :id="FIELD_IDS.name"
                :label="labels.name"
                required
                :error="errors.name"
            >
                <template #default="{ field }">
                    <Input
                        v-bind="field"
                        v-model="form.name"
                        :maxlength="NAME_MAX"
                        autocomplete="off"
                        :placeholder="labels.namePlaceholder"
                        data-test="name"
                        @input="emit('change')"
                        @blur="emit('blur', 'name')"
                    />
                </template>
            </FormField>
            <FormField
                :id="FIELD_IDS.description"
                :label="labels.description"
                required
                :helper="labels.descriptionHelper"
                :error="errors.description"
            >
                <template #default="{ field }">
                    <Textarea
                        v-bind="field"
                        v-model="form.description"
                        :maxlength="DESCRIPTION_MAX"
                        class="min-h-20"
                        data-test="description"
                        @input="emit('change')"
                        @blur="emit('blur', 'description')"
                    />
                </template>
            </FormField>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    :id="FIELD_IDS.category_id"
                    :label="labels.category"
                    :error="errors.category_id"
                >
                    <template #default="{ field }">
                        <NativeSelect
                            v-bind="field"
                            v-model="form.categoryId"
                            data-test="category"
                            @change="emit('change')"
                        >
                            <option
                                v-for="option in categories"
                                :key="option.id"
                                :value="option.id"
                            >
                                {{ option.name }}
                            </option>
                        </NativeSelect>
                    </template>
                </FormField>
                <FormField
                    :id="FIELD_IDS.type"
                    :label="labels.type"
                    :error="errors.type"
                >
                    <template #default="{ field }">
                        <NativeSelect
                            v-bind="field"
                            v-model="form.type"
                            data-test="type"
                            @change="
                                announce(
                                    labels.typeChanged(typeLabel(form.type)),
                                    'polite',
                                );
                                emit('change');
                            "
                        >
                            <option
                                v-for="option in BLOCK_TYPES"
                                :key="option.key"
                                :value="option.key"
                            >
                                {{ typeLabel(option.key) }}
                            </option>
                        </NativeSelect>
                    </template>
                </FormField>
            </div>
            <div class="grid gap-1.5" data-slot="form-field">
                <Label :for="FIELD_IDS.icon">{{ labels.icon }}</Label>
                <IconPicker
                    :id="FIELD_IDS.icon"
                    :model-value="form.icon"
                    :category-key="categoryIcon(categoryName)"
                    :category-name="categoryName"
                    @update:model-value="
                        form.icon = $event;
                        emit('change');
                    "
                />
            </div>
        </SectionCard>

        <SectionCard
            :title="labels.sections.display.title"
            :caption="labels.sections.display.caption"
            :icon="SlidersHorizontal"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    :id="FIELD_IDS['display.width']"
                    :label="labels.width"
                    :helper="
                        labels.widthHelper(
                            type.size.min_width,
                            type.size.max_width,
                        )
                    "
                    :error="errors['display.width']"
                >
                    <template #default="{ field }">
                        <Input
                            v-bind="field"
                            type="number"
                            inputmode="numeric"
                            :model-value="
                                Number.isNaN(form.display.width)
                                    ? ''
                                    : form.display.width
                            "
                            data-test="width"
                            @update:model-value="setNumber('width', $event)"
                        />
                    </template>
                </FormField>
                <FormField
                    :id="FIELD_IDS['display.height']"
                    :label="labels.height"
                    :helper="
                        labels.heightHelper(
                            heightName(type.size.min_height),
                            heightName(type.size.max_height),
                        )
                    "
                    :error="errors['display.height']"
                >
                    <template #default="{ field }">
                        <NativeSelect
                            v-bind="field"
                            v-model="form.display.height"
                            data-test="height"
                            @change="emit('change')"
                        >
                            <option v-for="h in HEIGHTS" :key="h" :value="h">
                                {{ heightLabel(h) }}
                            </option>
                        </NativeSelect>
                    </template>
                </FormField>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="[key, text, id] in toggles"
                    :key="key"
                    class="flex items-center justify-between gap-3 rounded-md border border-border-default px-3 py-2"
                >
                    <Label :for="id">{{ text }}</Label>
                    <Switch
                        :id="id"
                        :model-value="form.display[key]"
                        :data-test="key"
                        @update:model-value="
                            form.display[key] = $event;
                            emit('change');
                        "
                    />
                </div>
                <div
                    class="flex items-center justify-between gap-3 rounded-md border border-border-default px-3 py-2 sm:col-span-2"
                >
                    <div class="grid">
                        <Label for="wizard-show-footer-action">{{
                            labels.showFooterAction
                        }}</Label>
                        <span class="type-caption text-text-muted">{{
                            labels.showFooterActionHelper
                        }}</span>
                    </div>
                    <Switch
                        id="wizard-show-footer-action"
                        :model-value="form.display.show_footer_action"
                        data-test="show_footer_action"
                        @update:model-value="
                            form.display.show_footer_action = $event;
                            emit('change');
                        "
                    />
                </div>
                <div
                    v-if="form.display.show_footer_action"
                    class="grid gap-3 rounded-md border border-border-default p-3 sm:col-span-2 sm:grid-cols-2"
                    data-test="footer-action-config"
                >
                    <FormField
                        :id="FIELD_IDS['display.footer.target']"
                        :label="labels.footerTarget"
                        :error="errors['display.footer.target']"
                    >
                        <template #default="{ field }">
                            <NativeSelect
                                v-bind="field"
                                :model-value="form.display.footer.target"
                                data-test="footer-target"
                                @update:model-value="
                                    form.display.footer.target =
                                        $event === 'expanded_view'
                                            ? 'expanded_view'
                                            : 'external';
                                    emit('change');
                                "
                            >
                                <option value="external">
                                    {{ labels.footerTargets.external }}
                                </option>
                                <option value="expanded_view">
                                    {{ labels.footerTargets.expanded_view }}
                                </option>
                            </NativeSelect>
                        </template>
                    </FormField>
                    <FormField
                        :id="FIELD_IDS['display.footer.label']"
                        :label="labels.footerLabel"
                        :helper="labels.footerLabelHelper"
                        :error="errors['display.footer.label']"
                    >
                        <template #default="{ field }">
                            <Input
                                v-bind="field"
                                v-model="form.display.footer.label"
                                type="text"
                                maxlength="60"
                                autocomplete="off"
                                data-test="footer-label"
                                @input="emit('change')"
                                @blur="emit('blur', 'display.footer.label')"
                            />
                        </template>
                    </FormField>
                    <FormField
                        v-if="form.display.footer.target === 'external'"
                        class="sm:col-span-2"
                        :id="FIELD_IDS['display.footer.url']"
                        :label="labels.footerUrl"
                        :helper="labels.footerUrlHelper"
                        :error="errors['display.footer.url']"
                    >
                        <template #default="{ field }">
                            <Input
                                v-bind="field"
                                v-model="form.display.footer.url"
                                type="text"
                                maxlength="500"
                                autocomplete="off"
                                spellcheck="false"
                                data-test="footer-url"
                                @input="emit('change')"
                                @blur="emit('blur', 'display.footer.url')"
                            />
                        </template>
                    </FormField>
                    <p
                        v-else
                        class="type-caption text-text-secondary sm:col-span-2"
                        data-test="footer-expanded-note"
                    >
                        {{ labels.footerExpandedNote }}
                    </p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    :id="FIELD_IDS['display.min_width']"
                    :label="labels.minWidth"
                    :error="errors['display.min_width']"
                >
                    <template #default="{ field }">
                        <Input
                            v-bind="field"
                            type="number"
                            inputmode="numeric"
                            :model-value="
                                Number.isNaN(form.display.min_width)
                                    ? ''
                                    : form.display.min_width
                            "
                            data-test="min_width"
                            @update:model-value="setNumber('min_width', $event)"
                        />
                    </template>
                </FormField>
                <FormField
                    :id="FIELD_IDS['display.max_width']"
                    :label="labels.maxWidth"
                    :error="errors['display.max_width']"
                >
                    <template #default="{ field }">
                        <Input
                            v-bind="field"
                            type="number"
                            inputmode="numeric"
                            :model-value="
                                Number.isNaN(form.display.max_width)
                                    ? ''
                                    : form.display.max_width
                            "
                            data-test="max_width"
                            @update:model-value="setNumber('max_width', $event)"
                        />
                    </template>
                </FormField>
                <FormField
                    :id="FIELD_IDS['display.min_height']"
                    :label="labels.minHeight"
                    :error="errors['display.min_height']"
                >
                    <template #default="{ field }">
                        <NativeSelect
                            v-bind="field"
                            v-model="form.display.min_height"
                            data-test="min_height"
                            @change="emit('change')"
                        >
                            <option v-for="h in HEIGHTS" :key="h" :value="h">
                                {{ heightLabel(h) }}
                            </option>
                        </NativeSelect>
                    </template>
                </FormField>
                <FormField
                    :id="FIELD_IDS['display.max_height']"
                    :label="labels.maxHeight"
                    :error="errors['display.max_height']"
                >
                    <template #default="{ field }">
                        <NativeSelect
                            v-bind="field"
                            v-model="form.display.max_height"
                            data-test="max_height"
                            @change="emit('change')"
                        >
                            <option v-for="h in HEIGHTS" :key="h" :value="h">
                                {{ heightLabel(h) }}
                            </option>
                        </NativeSelect>
                    </template>
                </FormField>
            </div>
        </SectionCard>

        <SectionCard
            :title="labels.sections.period.title"
            :caption="labels.sections.period.caption"
            :icon="CalendarClock"
        >
            <FormField
                :id="FIELD_IDS['display.period']"
                :label="labels.period"
                :helper="labels.periodHelper"
                :error="errors['display.period']"
            >
                <template #default="{ field }">
                    <NativeSelect
                        v-bind="field"
                        v-model="form.display.period"
                        data-test="period"
                        @change="emit('change')"
                    >
                        <option
                            v-for="(text, key) in labels.periods"
                            :key="key"
                            :value="key"
                        >
                            {{ text }}
                        </option>
                    </NativeSelect>
                </template>
            </FormField>
        </SectionCard>
    </div>
</template>
