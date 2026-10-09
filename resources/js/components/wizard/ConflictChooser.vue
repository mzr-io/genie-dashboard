<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { sizeText, versionOf } from '@/lib/blockWizard';
import type { Version, WizardDraft } from '@/lib/blockWizard';
import { blockWizardLabels as labels } from '@/locales/labels';

// The revision-conflict chooser (Story 3.5): the Draft was saved by someone else (or by another tab) after the changes here began, so both
// versions are shown side by side and the Admin chooses. Nothing is written until the choice, and neither version is dropped silently.
const props = defineProps<{
    mine: Version;
    theirs: WizardDraft;
    busy?: boolean;
}>();

const emit = defineEmits<{ (e: 'keep-mine'): void; (e: 'use-theirs'): void }>();

const heading = ref<HTMLElement | null>(null);

function size(version: Version): string {
    return version.width !== null && version.height !== null
        ? sizeText({ width: version.width, height: version.height as never })
        : labels.conflictNone;
}

onMounted(async () => {
    await nextTick();
    heading.value?.focus();
});
</script>

<template>
    <section
        role="alert"
        data-test="conflict-chooser"
        class="flex flex-col gap-4 rounded-lg border border-border-default bg-surface-card p-4"
    >
        <div>
            <h2
                ref="heading"
                tabindex="-1"
                class="type-title-sm text-text-primary"
            >
                {{ labels.conflictTitle }}
            </h2>
            <p class="type-body-sm text-text-secondary">
                {{ labels.conflictBody }}
            </p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="column in [
                    {
                        key: 'mine',
                        title: labels.conflictMine,
                        version: props.mine,
                    },
                    {
                        key: 'theirs',
                        title: labels.conflictTheirs,
                        version: versionOf(props.theirs),
                    },
                ]"
                :key="column.key"
                :data-test="`conflict-${column.key}`"
                class="rounded-md border border-border-default p-3"
            >
                <h3 class="type-label-md text-text-primary">
                    {{ column.title }}
                </h3>
                <dl class="type-body-sm mt-2 grid gap-1">
                    <div>
                        <dt class="text-text-muted">
                            {{ labels.conflictName }}
                        </dt>
                        <dd class="break-words text-text-primary">
                            {{ column.version.name || labels.conflictNone }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">
                            {{ labels.conflictDescription }}
                        </dt>
                        <dd class="break-words text-text-primary">
                            {{
                                column.version.description ||
                                labels.conflictNone
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">
                            {{ labels.conflictSize }}
                        </dt>
                        <dd class="text-text-primary">
                            {{ size(column.version) }}
                        </dd>
                    </div>
                    <div v-if="column.version.savedAt">
                        <dt class="text-text-muted">
                            {{ labels.conflictSavedAt }}
                        </dt>
                        <dd class="text-text-primary">
                            {{ column.version.savedAt }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <Button
                type="button"
                :blocked="busy === true"
                data-test="conflict-keep-mine"
                @click="emit('keep-mine')"
            >
                {{ labels.conflictKeepMine }}
            </Button>
            <Button
                type="button"
                variant="secondary"
                :blocked="busy === true"
                data-test="conflict-use-theirs"
                @click="emit('use-theirs')"
            >
                {{ labels.conflictUseTheirs }}
            </Button>
        </div>
    </section>
</template>
