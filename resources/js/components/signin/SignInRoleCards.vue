<script setup lang="ts">
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { signInLabels as labels } from '@/locales/labels';

// The User and Admin role cards (UX-DR-91): a radiogroup with a visible radio at each card's top right.
// Selection also shows as a 2px accent-ink-strong border, never as the soft fill alone.
export type Role = 'user' | 'admin';

const role = defineModel<Role>({ required: true });
defineProps<{ id: string }>();

const cards: { value: Role; title: string; hint: string }[] = [
    { value: 'user', title: labels.roleUser, hint: labels.roleUserHint },
    { value: 'admin', title: labels.roleAdmin, hint: labels.roleAdminHint },
];
</script>

<template>
    <RadioGroup
        :id="id"
        v-model="role"
        name="role"
        orientation="horizontal"
        :aria-label="labels.roleGroup"
        class="grid grid-cols-2 gap-3"
        data-test="role-cards"
    >
        <label
            v-for="card in cards"
            :key="card.value"
            :for="`${id}-${card.value}`"
            :data-test="`role-card-${card.value}`"
            class="relative flex cursor-pointer flex-col gap-1 rounded-lg border border-border-default bg-surface-card p-4 pr-10 transition-colors has-data-[state=checked]:border-2 has-data-[state=checked]:border-accent-ink-strong has-data-[state=checked]:bg-accent-soft has-data-[state=checked]:p-[15px] has-data-[state=checked]:pr-[39px]"
        >
            <RadioGroupItem
                :id="`${id}-${card.value}`"
                :value="card.value"
                class="absolute top-4 right-4"
            />
            <span class="type-title-sm text-text-primary">
                {{ card.title }}<span class="sr-only"> · </span>
            </span>
            <span class="type-caption text-text-secondary">{{
                card.hint
            }}</span>
        </label>
    </RadioGroup>
</template>
