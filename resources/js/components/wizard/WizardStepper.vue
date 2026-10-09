<script setup lang="ts">
import { Check } from '@lucide/vue';
import { blockWizardLabels as labels } from '@/locales/labels';

// The wizard's progress (Story 3.4; UX-DR-86): a `nav` with an ordered list of the five steps. The current step carries
// `aria-current="step"`. A completed step collapses to a summary card with a check and "Edit"; you can jump back to one, and forward only
// to the first step that is not completed (`reached`). Later steps are plain text, "(not started)". Every button is named
// "{n}. {label}, completed|not completed".
export type StepItem = { key: string; label: string; summary?: string | null };

defineProps<{
    steps: StepItem[];
    // The step being shown (1-based).
    current: number;
    // The first step that is not completed: every step before it is completed.
    reached: number;
}>();

const emit = defineEmits<{ (e: 'go', step: number): void }>();
</script>

<template>
    <nav :aria-label="labels.progress" data-slot="wizard-stepper">
        <ol
            class="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-stretch"
        >
            <li
                v-for="(step, index) in steps"
                :key="step.key"
                :aria-current="index + 1 === current ? 'step' : undefined"
                :data-step="index + 1"
                :data-state="
                    index + 1 < reached
                        ? 'completed'
                        : index + 1 === reached
                          ? 'active'
                          : 'upcoming'
                "
                class="type-body-sm flex min-w-0 flex-1 basis-40 flex-col gap-0.5 rounded-lg border px-3 py-2"
                :class="
                    index + 1 === current
                        ? 'border-accent-ink-strong bg-accent-soft'
                        : 'border-border-default bg-surface-card'
                "
            >
                <button
                    v-if="index + 1 <= reached"
                    type="button"
                    :aria-label="
                        labels.stepButton(
                            index + 1,
                            step.label,
                            index + 1 < reached,
                        )
                    "
                    class="flex min-h-(--df-target-chrome) w-full items-center gap-2 rounded-sm text-start font-semibold text-text-primary"
                    data-test="step-button"
                    @click="emit('go', index + 1)"
                >
                    <span
                        v-if="index + 1 < reached"
                        aria-hidden="true"
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-success-soft text-success-text"
                        data-test="step-check"
                        ><Check class="size-3.5"
                    /></span>
                    <span
                        v-else
                        aria-hidden="true"
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded-full border border-border-control text-xs"
                        >{{ index + 1 }}</span
                    >
                    <span aria-hidden="true" class="min-w-0 flex-1 truncate">{{
                        step.label
                    }}</span>
                    <span
                        v-if="index + 1 < reached"
                        aria-hidden="true"
                        class="type-caption font-semibold text-accent-ink underline underline-offset-2"
                        data-test="step-edit"
                        >{{ labels.edit }}</span
                    >
                </button>
                <p
                    v-else
                    class="flex min-h-(--df-target-chrome) items-center gap-2 text-text-muted"
                    data-test="step-upcoming"
                >
                    <span
                        aria-hidden="true"
                        class="inline-flex size-5 shrink-0 items-center justify-center rounded-full border border-border-default text-xs"
                        >{{ index + 1 }}</span
                    >
                    <span>{{ step.label }} {{ labels.notStarted }}</span>
                </p>
                <p
                    v-if="index + 1 < reached && step.summary"
                    class="type-caption truncate ps-7 text-text-muted"
                    data-test="step-summary"
                >
                    {{ step.summary }}
                </p>
            </li>
        </ol>
    </nav>
</template>
