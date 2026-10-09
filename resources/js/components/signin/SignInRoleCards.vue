<script setup lang="ts">
import { Settings2, UserRound } from '@lucide/vue';
import type { Component } from 'vue';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { signInLabels as labels } from '@/locales/labels';

// The User and Admin role cards (UX-DR-91): a radiogroup with a visible radio at each card's top right.
// Selection also shows as a 2px accent-ink-strong border, never as the soft fill alone.
export type Role = 'user' | 'admin';

const role = defineModel<Role>({ required: true });
defineProps<{ id: string }>();

const cards: { value: Role; title: string; hint: string; icon: Component }[] = [
    {
        value: 'user',
        title: labels.roleUser,
        hint: labels.roleUserHint,
        icon: UserRound,
    },
    {
        value: 'admin',
        title: labels.roleAdmin,
        hint: labels.roleAdminHint,
        icon: Settings2,
    },
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
            class="group relative flex cursor-pointer flex-col gap-3 rounded-lg border border-border-default bg-surface-card p-4 transition-colors hover:border-border-control has-focus-visible:ring-2 has-focus-visible:ring-accent-ink-strong has-focus-visible:ring-offset-2 has-data-[state=checked]:border-2 has-data-[state=checked]:border-accent-ink-strong has-data-[state=checked]:bg-accent-soft has-data-[state=checked]:p-[15px]"
        >
            <!-- The radio sits in its own positioned box: .hit-area sets position: relative on the radio itself. -->
            <span
                class="absolute top-4 right-4 flex group-has-data-[state=checked]:top-[15px] group-has-data-[state=checked]:right-[15px]"
            >
                <RadioGroupItem
                    :id="`${id}-${card.value}`"
                    :value="card.value"
                />
            </span>
            <span
                class="flex size-9 items-center justify-center rounded-md bg-surface-sunken text-text-secondary transition-colors group-has-data-[state=checked]:bg-surface-card group-has-data-[state=checked]:text-accent-ink-strong"
                aria-hidden="true"
            >
                <component :is="card.icon" class="size-5" />
            </span>
            <span class="flex flex-col gap-0.5">
                <span class="type-title-sm text-text-primary">
                    {{ card.title }}<span class="sr-only"> · </span>
                </span>
                <span class="type-caption text-text-secondary">{{
                    card.hint
                }}</span>
            </span>
        </label>
    </RadioGroup>
</template>
