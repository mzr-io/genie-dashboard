<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
} from 'vue';
import { useI18n } from 'vue-i18n';
import GatedAction from '@/components/GatedAction.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import ConfigureBlockStep from '@/components/wizard/ConfigureBlockStep.vue';
import LivePreview from '@/components/wizard/LivePreview.vue';
import WizardStepper from '@/components/wizard/WizardStepper.vue';
import { announce } from '@/lib/announce';
import {
    BLOCK_TYPES,
    DESCRIPTION_MAX,
    FIELD_IDS,
    NAME_MAX,
    STEP_COUNT,
    WizardError,
    blockTypeFor,
    createWizardDraft,
    defaultDisplay,
    fetchCategories,
    fetchWizardDraft,
    heightName,
    mergedDisplay,
    saveWizardDraft,
    validateDisplay,
} from '@/lib/blockWizard';
import type {
    BlockCategory,
    DraftConfig,
    SaveBody,
    StepForm,
    WizardDraft,
} from '@/lib/blockWizard';
import { SIGN_IN_URL } from '@/lib/session';
import { blockWizardLabels as labels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// The Create block wizard (Story 3.4): five steps with a progress `nav`, Step 1 (Configure Block) and a sticky live preview in steps 1 to 3.
// It opens a Draft from `?draft=<id>&step=<n>`, or starts one on the first Save draft or Continue. Steps 2 to 5 are placeholders until their
// stories. There is no autosave yet (Story 3.5): the Admin saves with Save draft or Continue, and an unsaved change warns before leaving.
const { t } = useI18n();
const toasts = useToasts();

const params = new URLSearchParams(
    typeof window === 'undefined' ? '' : window.location.search,
);
const requestedStep = Number.parseInt(params.get('step') ?? '', 10);

const state = ref<'loading' | 'error' | 'forbidden' | 'notfound' | 'ready'>(
    'loading',
);
const draft = ref<WizardDraft | null>(null);
const categories = ref<BlockCategory[]>([]);
// The first step that is not completed; every step before it is completed.
const reached = ref(1);
const current = ref(1);
const saving = ref(false);
const dirty = ref(false);
const errors = reactive<Record<string, string | undefined>>({});

const type = BLOCK_TYPES[0];
const form = reactive<StepForm>({
    name: '',
    description: '',
    categoryId: '',
    type: type.key,
    icon: null,
    display: defaultDisplay(type),
});

const blockType = computed(() => blockTypeFor(form.type) ?? type);
const categoryName = computed(
    () => categories.value.find((c) => c.id === form.categoryId)?.name ?? '',
);
const heading = computed(() => form.name.trim() || labels.untitled);
const version = computed(() => draft.value?.version ?? '1.0');
const showPreview = computed(() => current.value <= 3);
const steps = computed(() =>
    labels.steps.map((step, index) => ({
        ...step,
        summary:
            index === 0
                ? labels.summary(
                      form.display.width,
                      heightName(form.display.height),
                      categoryName.value,
                  )
                : null,
    })),
);

function clampStep(value: number): number {
    return Math.min(Math.max(value, 1), STEP_COUNT);
}

function clearErrors(): void {
    for (const key of Object.keys(errors)) {
        delete errors[key];
    }
}

function applyDraft(next: WizardDraft): void {
    draft.value = next;
    const known = blockTypeFor(next.type) ?? type;

    form.name = next.name;
    form.description = next.config.description ?? '';
    form.categoryId = next.category_id ?? form.categoryId;

    // The Draft's own category stays an option even when it has since been archived, so saving does not change it.
    if (
        next.category_id &&
        !categories.value.some((c) => c.id === next.category_id)
    ) {
        categories.value = [
            ...categories.value,
            { id: next.category_id, name: next.category ?? '', archived: true },
        ];
    }
    form.type = known.key;
    form.icon = next.config.icon ?? null;
    form.display = mergedDisplay(known, next.config.display);
}

function general(): BlockCategory | null {
    return (
        categories.value.find((c) => c.name.toLowerCase() === 'general') ??
        categories.value[0] ??
        null
    );
}

function sessionEnded(error: unknown): boolean {
    if (
        error instanceof WizardError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return true;
    }

    return false;
}

async function load(): Promise<void> {
    state.value = 'loading';

    try {
        categories.value = await fetchCategories();
        form.categoryId = general()?.id ?? '';

        const id = params.get('draft');

        if (id) {
            const loaded = await fetchWizardDraft(id);

            applyDraft(loaded);
            reached.value = clampStep(loaded.step);
            current.value = Number.isNaN(requestedStep)
                ? reached.value
                : Math.min(clampStep(requestedStep), reached.value);
        }

        state.value = 'ready';
    } catch (error) {
        if (sessionEnded(error)) {
            return;
        }

        state.value =
            error instanceof WizardError && error.status === 403
                ? 'forbidden'
                : error instanceof WizardError && error.status === 404
                  ? 'notfound'
                  : 'error';
    }
}

// ---- Validation ------------------------------------------------------------------------------------------------

function checkName(): string | undefined {
    const name = form.name.trim();

    return name === ''
        ? labels.nameRequired
        : name.length > NAME_MAX
          ? labels.nameTooLong(NAME_MAX)
          : undefined;
}

function checkDescription(): string | undefined {
    const text = form.description.trim();

    return text === ''
        ? labels.descriptionRequired
        : text.length > DESCRIPTION_MAX
          ? labels.descriptionTooLong(DESCRIPTION_MAX)
          : undefined;
}

// Fills `errors` for Step 1. A Draft saves with only a name and a valid size, so the description is required on Continue alone.
function validate(requireDescription: boolean): boolean {
    clearErrors();
    errors.name = checkName();
    errors.description = requireDescription ? checkDescription() : undefined;

    for (const [key, message] of Object.entries(
        validateDisplay(form.display, blockType.value),
    )) {
        errors[`display.${key}`] = message;
    }

    return Object.keys(FIELD_IDS).every((key) => !errors[key]);
}

async function focusFirstError(): Promise<void> {
    await nextTick();
    const first = Object.keys(FIELD_IDS).find((key) => errors[key]);

    if (first) {
        document.getElementById(FIELD_IDS[first])?.focus();
    }
}

function onBlur(field: string): void {
    if (field === 'name') {
        errors.name = checkName();
    } else if (field === 'description') {
        errors.description = checkDescription();
    }
}

function onChange(): void {
    dirty.value = true;
}

// ---- Saving ----------------------------------------------------------------------------------------------------

function configOf(): DraftConfig {
    const description = form.description.trim();

    return {
        schema_version: 1,
        ...(description === '' ? {} : { description }),
        ...(form.icon === null ? {} : { icon: form.icon }),
        display: { ...form.display },
    };
}

function showWizardUrl(id: string): void {
    const url = new URL(window.location.href);

    url.searchParams.set('draft', id);
    url.searchParams.set('step', String(current.value));
    window.history.replaceState(window.history.state, '', url);
}

// The server's field errors, keyed as the form's: "config.display.width" is the width field.
function showFieldErrors(fields: Record<string, string[]>): void {
    for (const [key, messages] of Object.entries(fields)) {
        const field = key.replace(/^config\./, '');

        errors[field] = messages[0];
    }
}

// Starts the Draft when there is none, then saves the form. `step` is the furthest step the Draft reaches. Returns whether it was saved.
async function persist(step: number): Promise<boolean> {
    if (saving.value) {
        return false;
    }

    saving.value = true;

    try {
        let target = draft.value;

        if (target === null) {
            target = await createWizardDraft(form.name.trim(), form.type);
            draft.value = target;
            showWizardUrl(target.draft_id);
        }

        const body: SaveBody = {
            name: form.name.trim(),
            type: form.type,
            expected_revision: target.revision,
            lock_epoch: target.lock_epoch,
            category_id: form.categoryId || null,
            step,
            config: configOf(),
        };

        draft.value = await saveWizardDraft(target.draft_id, body);
        dirty.value = false;

        return true;
    } catch (error) {
        if (sessionEnded(error)) {
            return false;
        }

        if (error instanceof WizardError && error.status === 422) {
            showFieldErrors(error.fields);

            // The icon picker shows no error text, so an icon error counts as unmapped.
            if (
                Object.keys(FIELD_IDS).some(
                    (key) => key !== 'icon' && errors[key],
                )
            ) {
                await focusFirstError();
            } else {
                // Nothing the form can point at (an icon, a step, a refused secret): say so, and move focus to the message.
                toasts.add({ kind: 'error', message: t('save-failed.form') });
                await nextTick();
                document
                    .querySelector<HTMLElement>(
                        '[data-slot="toast"] [data-slot="toast-dismiss"]',
                    )
                    ?.focus();
            }
        } else if (error instanceof WizardError && error.status === 409) {
            toasts.add({ kind: 'error', message: labels.conflict });
        } else if (error instanceof WizardError && error.status === 423) {
            toasts.add({ kind: 'error', message: labels.lockLost });
        } else if (error instanceof WizardError && error.status === 404) {
            state.value = 'notfound';
        } else {
            toasts.add({
                kind: 'error',
                message:
                    error instanceof WizardError && error.status === 429
                        ? t('throttled')
                        : t('save-failed.form'),
            });
        }

        return false;
    } finally {
        saving.value = false;
    }
}

async function saveDraft(): Promise<void> {
    if (!validate(false)) {
        await focusFirstError();

        return;
    }

    if (await persist(reached.value)) {
        announce(labels.saved, 'polite');
    }
}

async function onContinue(): Promise<void> {
    if (!validate(true)) {
        await focusFirstError();

        return;
    }

    const next = Math.min(current.value + 1, STEP_COUNT);
    const furthest = Math.max(reached.value, next);

    if (await persist(furthest)) {
        reached.value = furthest;
        current.value = next;

        if (draft.value) {
            showWizardUrl(draft.value.draft_id);
        }
    }
}

function goTo(step: number): void {
    // Back to a completed step, forward only to the first one that is not completed.
    if (step < 1 || step > reached.value) {
        announce(labels.notReady, 'polite');

        return;
    }

    current.value = step;

    if (draft.value) {
        showWizardUrl(draft.value.draft_id);
    }
}

function onPreview(): void {
    goTo(4);
}

function onPublish(): void {
    // Publishing is the last step's work (Story 3.19); until the earlier steps exist this only points the way.
    goTo(5);
}

function beforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) {
        event.preventDefault();
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    void load();
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
});
</script>

<template>
    <Head :title="labels.pageTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <header
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3"
            data-slot="wizard-header"
        >
            <div class="min-w-0">
                <p class="type-caption text-text-muted" data-test="breadcrumb">
                    {{ labels.breadcrumb }}
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h1
                        class="type-headline-md min-w-0 break-words text-text-primary"
                        data-test="title"
                    >
                        {{ heading }}
                    </h1>
                    <Badge variant="draft" data-test="draft-badge">{{
                        labels.draftBadge
                    }}</Badge>
                </div>
            </div>
            <div
                v-if="state === 'ready'"
                class="flex flex-wrap items-start gap-2"
                data-slot="wizard-actions"
            >
                <Button
                    type="button"
                    variant="secondary"
                    :aria-disabled="saving ? 'true' : undefined"
                    data-test="save-draft"
                    @click="saveDraft"
                >
                    {{ saving ? labels.saving : labels.saveDraft }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="preview"
                    @click="onPreview"
                >
                    {{ labels.preview }}
                </Button>
                <GatedAction
                    permission="blocks.publish"
                    :reason="t('perm-publish')"
                    variant="primary"
                    data-test="publish"
                    @click="onPublish"
                >
                    {{ labels.publish }}
                </GatedAction>
            </div>
        </header>

        <div
            v-if="state === 'loading'"
            role="status"
            aria-busy="true"
            class="flex flex-col gap-3"
        >
            <span class="sr-only">{{ labels.loading }}</span>
            <Skeleton class="h-16 w-full" :caption="false" />
            <Skeleton class="h-72 w-full" :caption="false" />
        </div>

        <p
            v-else-if="state === 'forbidden'"
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>

        <section
            v-else-if="state === 'notfound'"
            data-slot="draft-not-found"
            class="flex flex-col items-start gap-4 rounded-lg border border-border-default bg-surface-card p-6"
        >
            <p class="type-body-md text-text-secondary" role="alert">
                {{ labels.notFound }}
            </p>
            <Button as-child variant="secondary">
                <Link href="/admin/blocks/drafts">{{
                    labels.backToDrafts
                }}</Link>
            </Button>
        </section>

        <section
            v-else-if="state === 'error'"
            data-slot="draft-load-failed"
            class="flex flex-col items-start gap-4 rounded-lg border border-border-default bg-surface-card p-6"
        >
            <p class="type-body-md text-text-secondary" role="alert">
                {{ labels.loadFailed }}
            </p>
            <Button
                type="button"
                variant="secondary"
                data-test="retry"
                @click="load"
                >{{ labels.retry }}</Button
            >
        </section>

        <template v-else>
            <WizardStepper
                :steps="steps"
                :current="current"
                :reached="reached"
                @go="goTo"
            />

            <div
                class="grid gap-6"
                :class="
                    showPreview
                        ? 'lg:grid-cols-[minmax(0,11fr)_minmax(0,9fr)]'
                        : ''
                "
            >
                <div class="min-w-0">
                    <form
                        v-if="current === 1"
                        novalidate
                        class="flex flex-col gap-4"
                        data-test="step-1"
                        @submit.prevent="onContinue"
                    >
                        <ConfigureBlockStep
                            :form="form"
                            :errors="errors"
                            :categories="categories"
                            @change="onChange"
                            @blur="onBlur"
                        />
                        <div class="flex justify-end gap-2">
                            <Button
                                type="submit"
                                :aria-disabled="saving ? 'true' : undefined"
                                data-test="continue"
                            >
                                {{ labels.continue }}
                            </Button>
                        </div>
                    </form>

                    <section
                        v-else
                        data-test="later-step"
                        class="flex flex-col items-start gap-4 rounded-lg border border-border-default bg-surface-card p-6"
                    >
                        <h2 class="type-title-sm text-text-primary">
                            {{
                                labels.stepStatus(
                                    current,
                                    STEP_COUNT,
                                    labels.steps[current - 1].label,
                                )
                            }}
                        </h2>
                        <p class="type-body-md text-text-secondary">
                            {{ labels.laterStep }}
                        </p>
                        <Button
                            type="button"
                            variant="secondary"
                            data-test="back"
                            @click="goTo(current - 1)"
                        >
                            {{ labels.back }}
                        </Button>
                    </section>
                </div>

                <div
                    v-if="showPreview"
                    class="min-w-0 lg:sticky lg:top-4 lg:self-start"
                    data-test="preview-column"
                >
                    <LivePreview
                        :name="form.name"
                        :description="form.description"
                        :display="form.display"
                        :version="version"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
