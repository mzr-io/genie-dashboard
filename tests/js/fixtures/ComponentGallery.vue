<script setup lang="ts">
import { Info } from '@lucide/vue';
import { ref } from 'vue';
import BlockedReason from '@/components/BlockedReason.vue';
import CategoryChip from '@/components/CategoryChip.vue';
import CategoryChips from '@/components/CategoryChips.vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import LockBadge from '@/components/LockBadge.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import SecretField from '@/components/SecretField.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import StatusDot from '@/components/StatusDot.vue';
import Tag from '@/components/Tag.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useBlurValidation } from '@/composables/useBlurValidation';
import { galleryLabels as g } from './galleryLabels';

// Every shared control in one place. Tests mount it; no route renders it (Story 1.8).
const name = ref('');
const email = ref('');
const notes = ref('');
const token = ref('');
const replacing = ref(false);
const device = ref('desktop');
const category = ref('all');
const access = ref('everyone');
const remember = ref(false);
const shortcuts = ref(false);
const submitted = ref(0);
const blockedRan = ref(0);

const form = useBlurValidation({
    name: {
        label: g.name,
        value: () => name.value,
        validate: (v) => (String(v).trim() === '' ? g.nameError : null),
    },
    email: {
        label: g.email,
        value: () => email.value,
        validate: (v) =>
            /^\S+@\S+\.\S+$/.test(String(v)) ? null : g.emailError,
    },
});

const { summary: summaryRef } = form;

const blockedReasonId = 'gallery-blocked-reason';

function nameInput(): HTMLElement | null {
    return document.getElementById(form.fieldId('name'));
}

function save(): void {
    void form.submit(() => {
        submitted.value += 1;
    });
}
</script>

<template>
    <TooltipProvider>
        <div data-slot="component-gallery" class="grid gap-8 p-6">
            <form class="grid max-w-md gap-4" novalidate @submit.prevent="save">
                <FormErrorSummary
                    v-if="form.showSummary.value"
                    ref="summaryRef"
                    :items="form.summaryItems.value"
                />
                <RequiredNote />
                <FormField
                    :id="form.fieldId('name')"
                    :label="g.name"
                    :helper="g.nameHelper"
                    :error="form.errors.name"
                    required
                    #default="{ field }"
                >
                    <Input
                        v-bind="field"
                        v-model="name"
                        @blur="form.onBlur('name')"
                    />
                </FormField>
                <FormField
                    :id="form.fieldId('email')"
                    :label="g.email"
                    :error="form.errors.email"
                    required
                    #default="{ field }"
                >
                    <Input
                        v-bind="field"
                        v-model="email"
                        type="email"
                        autocomplete="email"
                        @blur="form.onBlur('email')"
                    />
                </FormField>
                <FormField :label="g.token" #default="{ field }">
                    <SecretField
                        v-bind="field"
                        v-model="token"
                        v-model:replacing="replacing"
                        saved-at="2026-10-02T09:30:00Z"
                    />
                </FormField>
                <FormField :label="g.notes" #default="{ field }">
                    <Textarea v-bind="field" v-model="notes" />
                </FormField>
                <FormField :label="g.plan" #default="{ field }">
                    <Select default-value="test">
                        <SelectTrigger v-bind="field">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="test">{{
                                g.planTest
                            }}</SelectItem>
                            <SelectItem
                                value="live"
                                disabled
                                :reason="g.planLiveReason"
                                >{{ g.planLive }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </FormField>
                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit">{{ g.save }}</Button>
                    <Button variant="secondary" type="button">{{
                        g.secondary
                    }}</Button>
                    <Button variant="ghost" type="button">{{ g.ghost }}</Button>
                    <Button variant="link" type="button">{{ g.link }}</Button>
                    <Button variant="destructive-soft" type="button">{{
                        g.destructiveSoft
                    }}</Button>
                    <Button variant="destructive" type="button">{{
                        g.destructive
                    }}</Button>
                </div>
                <div class="grid gap-1">
                    <Button
                        variant="secondary"
                        type="button"
                        data-test="blocked"
                        blocked
                        :blocked-reason="g.blockedReason"
                        :aria-describedby="blockedReasonId"
                        :first-blocker="nameInput"
                        @click="blockedRan += 1"
                        >{{ g.blocked }}</Button
                    >
                    <BlockedReason :id="blockedReasonId">{{
                        g.blockedReason
                    }}</BlockedReason>
                </div>
                <output data-test="submitted">{{ submitted }}</output>
                <output data-test="blocked-ran">{{ blockedRan }}</output>
            </form>

            <section class="grid gap-4">
                <SegmentedControl
                    v-model="device"
                    :label="g.device"
                    :options="[
                        { value: 'desktop', label: g.desktop },
                        { value: 'tablet', label: g.tablet },
                        { value: 'mobile', label: g.mobile },
                    ]"
                />
                <CategoryChips v-model="category" :label="g.category">
                    <CategoryChip value="all">{{ g.all }}</CategoryChip>
                    <CategoryChip value="sales">{{ g.sales }}</CategoryChip>
                    <CategoryChip value="people">{{ g.people }}</CategoryChip>
                </CategoryChips>
                <RadioGroup v-model="access" :aria-label="g.access">
                    <div class="flex items-center gap-2">
                        <RadioGroupItem
                            id="gallery-access-all"
                            value="everyone"
                        />
                        <Label for="gallery-access-all">{{ g.everyone }}</Label>
                    </div>
                    <div class="flex items-center gap-2">
                        <RadioGroupItem
                            id="gallery-access-groups"
                            value="groups"
                        />
                        <Label for="gallery-access-groups">{{
                            g.groups
                        }}</Label>
                    </div>
                </RadioGroup>
                <div class="flex items-center gap-2">
                    <Checkbox id="gallery-remember" v-model="remember" />
                    <Label for="gallery-remember">{{ g.remember }}</Label>
                </div>
                <div class="flex items-center gap-2">
                    <Switch id="gallery-shortcuts" v-model="shortcuts" />
                    <Label for="gallery-shortcuts">{{ g.shortcuts }}</Label>
                </div>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            type="button"
                            :aria-label="g.tooltipTrigger"
                        >
                            <Info aria-hidden="true" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{{ g.tooltipText }}</TooltipContent>
                </Tooltip>
            </section>

            <section class="flex flex-wrap items-center gap-2">
                <Tag>{{ g.version }}</Tag>
                <Badge variant="neutral">{{ g.status }}</Badge>
                <Badge variant="draft" dot>{{ g.draft }}</Badge>
                <Badge variant="operational" dot>{{ g.operational }}</Badge>
                <LockBadge />
                <span class="inline-flex items-center gap-1">
                    <StatusDot tone="success" />
                    {{ g.operational }}
                </span>
            </section>

            <section class="grid max-w-md gap-2">
                <Skeleton class="h-4 w-48" :caption="false" />
                <Skeleton class="h-4 w-64" />
            </section>
        </div>
    </TooltipProvider>
</template>
