<script setup lang="ts">
import { Users } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import BlockedReason from '@/components/BlockedReason.vue';
import FetchErrorCard from '@/components/FetchErrorCard.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SampleTree from '@/components/wizard/SampleTree.vue';
import SectionCard from '@/components/wizard/SectionCard.vue';
import { useShell } from '@/composables/useShell';
import { announce } from '@/lib/announce';
import { clockText } from '@/lib/blockSample';
import type { FetchBody } from '@/lib/blockSample';
import {
    endAsUser,
    fetchUserMembers,
    fetchUserShape,
    startUserFetch,
} from '@/lib/blockUserSample';
import type { AsUserState, UserMember } from '@/lib/blockUserSample';
import { WizardError } from '@/lib/blockWizard';
import { DataSourceError, PollTimeout, pollOperation } from '@/lib/dataSources';
import type { ConnectionTestSummary } from '@/lib/dataSources';
import { blockAsUserLabels as labels } from '@/locales/labels';

// Fetch as user (Story 3.17) in Step 2, and Preview as in Step 4: pick an active member (search narrows the list; their groups are shown),
// start the Connector's `fetch_as_user` Operation and poll it. The server resolves the member's ID, email, group and attributes and sends the
// request; the browser sends only the member and the request's non-bound choices, and gets back the value-free structure (paths and types) of
// the response. The response itself stays in the Operation's blob, for this Admin only, and is never saved. Without `data.preview_as_user` the
// controls are `aria-disabled` with the reason (the server refuses, and audits, an attempt anyway). A member without a bound attribute ends in
// the shared error card naming the missing keys: no request was made.
const props = defineProps<{
    asUser: AsUserState;
    draftId: string | null;
    // What the request is now (read when the action is activated), its key and the Data Source's name for the error card.
    fetchBody: () => FetchBody;
    requestKey: string;
    sourceName: string;
    // Why nothing can be fetched right now beyond this panel's own reasons (the form is incomplete, offline); undefined when it can.
    fetchReason?: string;
    // `fetch`: the Step 2 section with the structure tree. `preview`: the compact Step 4 control with the data switch.
    mode: 'fetch' | 'preview';
}>();

const emit = defineEmits<{
    // A result is ready (and Step 4 now previews as this member).
    (e: 'fetched'): void;
    (e: 'auth-error', error: unknown): void;
}>();

const { can } = useShell();
const searchId = useId();
const memberId = useId();
const reasonId = useId();
const statusId = useId();
const card = ref<InstanceType<typeof FetchErrorCard> | null>(null);
const members = ref<UserMember[] | null>(null);
const membersFailed = ref(false);
const search = ref('');
const chosen = ref(props.asUser.membershipId);
let controller: AbortController | null = null;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let waitTimer: ReturnType<typeof setInterval> | null = null;
let loadSeq = 0;

const state = computed(() => props.asUser);
const mayPreview = computed(() => can.value['data.preview_as_user'] === true);
const running = computed(() => state.value.phase === 'running');
const chosenMember = computed(
    () => members.value?.find((m) => m.membership_id === chosen.value) ?? null,
);
const hasResult = computed(() => state.value.operationId !== null);
const earlier = computed(
    () => hasResult.value && state.value.requestKey !== props.requestKey,
);
const until = computed(() => clockText(state.value.expiresAt));
const useOptions = computed(() => [
    { value: 'shared', label: labels.sourceOptions[0].label },
    { value: 'user', label: labels.previewingAs(state.value.name) },
]);

// Why the action cannot run, in order: the permission, the form's own reason, no member chosen, a wait the server asked for.
const blocked = computed<string | undefined>(() => {
    if (!mayPreview.value) {
        return labels.noPermission;
    }

    if (props.fetchReason) {
        return props.fetchReason;
    }

    if (chosen.value === '') {
        return labels.memberRequired;
    }

    return state.value.wait !== null
        ? labels.waitFor(state.value.wait)
        : undefined;
});

async function loadMembers(): Promise<void> {
    if (!mayPreview.value) {
        return;
    }

    const mine = (loadSeq += 1);

    try {
        const found = await fetchUserMembers(search.value);

        if (mine !== loadSeq) {
            return;
        }

        membersFailed.value = false;
        members.value = found;

        // A member the narrowing dropped is no longer a choice, unless a result for them is in use.
        if (
            chosen.value !== '' &&
            chosen.value !== state.value.membershipId &&
            !found.some((m) => m.membership_id === chosen.value)
        ) {
            chosen.value = '';
        }
    } catch (error) {
        if (mine !== loadSeq) {
            return;
        }

        if (
            error instanceof WizardError &&
            (error.status === 401 || error.status === 419)
        ) {
            emit('auth-error', error);

            return;
        }

        membersFailed.value = true;
    }
}

function onSearch(): void {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    searchTimer = setTimeout(() => void loadMembers(), 300);
}

onMounted(() => void loadMembers());

onBeforeUnmount(() => {
    controller?.abort();
    controller = null;

    if (state.value.phase === 'running') {
        state.value.phase = 'idle';
    }

    if (searchTimer !== null) {
        clearTimeout(searchTimer);
    }

    if (waitTimer !== null) {
        clearInterval(waitTimer);
    }
});

// A result that ended elsewhere (expired in Step 4) starts a clean panel.
watch(
    () => state.value.operationId,
    (id) => {
        if (id === null && state.value.phase === 'ok') {
            state.value.phase = 'idle';
        }
    },
);

function startWait(seconds: number): void {
    if (waitTimer !== null) {
        clearInterval(waitTimer);
    }

    state.value.wait = Math.max(1, Math.ceil(seconds));
    waitTimer = setInterval(() => {
        const left = (state.value.wait ?? 0) - 1;

        if (left <= 0) {
            state.value.wait = null;

            if (waitTimer !== null) {
                clearInterval(waitTimer);
                waitTimer = null;
            }

            return;
        }

        state.value.wait = left;
    }, 1000);
}

function ended(error: unknown): boolean {
    return (
        (error instanceof WizardError || error instanceof DataSourceError) &&
        (error.status === 401 || error.status === 419)
    );
}

function refusalOf(error: WizardError): string {
    if (error.status === 403) {
        return labels.noPermission;
    }

    if (error.status === 404) {
        return labels.memberGone;
    }

    if (error.status === 422) {
        return Object.values(error.fields)[0]?.[0] ?? labels.refused;
    }

    return labels.startFailed;
}

async function run(): Promise<void> {
    const draftId = props.draftId;

    if (running.value) {
        return;
    }

    // Retry on the card is not `aria-disabled` itself: say why nothing happened.
    if (blocked.value !== undefined || draftId === null) {
        state.value.refusal = blocked.value ?? labels.startFailed;

        return;
    }

    controller?.abort();
    controller = new AbortController();
    const mine = controller;
    const body = props.fetchBody();
    const target = chosenMember.value;
    const asUser = state.value;

    // This run was cancelled (the Admin cleared the result, or left): nothing it learned may land, and a phase it left `running` is let go.
    const bail = (): boolean => {
        if (!mine.signal.aborted) {
            return false;
        }

        if (controller === null && asUser.phase === 'running') {
            asUser.phase = 'idle';
        }

        return true;
    };

    asUser.phase = 'running';
    asUser.refusal = null;
    asUser.failure = null;
    asUser.ended = false;

    try {
        const started = await startUserFetch(draftId, chosen.value, body);
        const operation = await pollOperation(
            started.operation_id,
            mine.signal,
        );
        const result =
            operation.result as Partial<ConnectionTestSummary> | null;

        if (operation.status === 'stale') {
            asUser.phase = 'stale';
            announce(labels.stale, 'polite');

            return;
        }

        if (operation.status === 'expired') {
            asUser.phase = 'expired';
            announce(labels.expired, 'polite');

            return;
        }

        if (operation.status === 'succeeded' && result?.ok) {
            const ref = {
                as_user_operation: started.operation_id,
                data_source_id: body.data_source_id,
                endpoint_id: body.endpoint_id,
            };
            let read: Awaited<ReturnType<typeof fetchUserShape>> = null;

            try {
                read = await fetchUserShape(draftId, ref, mine.signal);
            } catch (error) {
                if (ended(error) || mine.signal.aborted) {
                    throw error;
                }

                // A network or server failure is not an expiry: say the read failed.
                asUser.phase = 'idle';
                asUser.refusal = labels.readFailed;

                return;
            }

            if (bail()) {
                return;
            }

            // Gone already: the fetch has to be made again.
            if (read === null) {
                asUser.phase = 'expired';

                return;
            }

            asUser.operationId = started.operation_id;
            asUser.membershipId = chosen.value;
            asUser.name = target?.name ?? asUser.name;
            asUser.dataSourceId = body.data_source_id;
            asUser.endpointId = body.endpoint_id;
            asUser.requestKey = body.request_key;
            asUser.expiresAt = read.expires_at;
            asUser.shape = read.shape;
            asUser.using = true;
            asUser.phase = 'ok';
            announce(
                labels.done(asUser.name, clockText(read.expires_at)),
                'polite',
            );
            emit('fetched');

            return;
        }

        asUser.failure = {
            code: result?.code ?? 'fetch-failed',
            reason: result?.reason ?? operation.status,
            missing: result?.missing ?? null,
            status: result?.status ?? null,
            requestId: result?.request_id ?? null,
            host: result?.host ?? null,
        };
        asUser.phase = 'failure';
        await nextTick();
        card.value?.focus();
    } catch (error) {
        if (bail()) {
            return;
        }

        if (ended(error)) {
            asUser.phase = 'idle';
            emit('auth-error', error);

            return;
        }

        if (error instanceof WizardError) {
            asUser.phase = 'idle';

            if (error.status === 429) {
                const after = error.body.retry_after;

                startWait(typeof after === 'number' ? after : 5);

                return;
            }

            asUser.refusal = refusalOf(error);

            return;
        }

        // The Operation could not be read, or no worker answered: a failed call like any other, with its card.
        asUser.failure = {
            code: 'fetch-failed',
            reason: error instanceof PollTimeout ? 'no_worker_responded' : null,
            missing: null,
            status: null,
            requestId: null,
            host: null,
        };
        asUser.phase = 'failure';
        await nextTick();
        card.value?.focus();
    }
}

function setSource(value: string): void {
    state.value.using = value === 'user';
    // Using it again clears the notice of an earlier end.
    state.value.ended = false;
}

function clear(): void {
    controller?.abort();
    controller = null;
    endAsUser(state.value, false);
    state.value.failure = null;
}

defineExpose({ run });

// Keeps the control and the in-use result in step when a result is made elsewhere.
watch(
    () => state.value.membershipId,
    (id) => {
        if (id !== '') {
            chosen.value = id;
        }
    },
);

const title = computed(() =>
    props.mode === 'fetch' ? labels.fetchTitle : labels.previewTitle,
);
const caption = computed(() =>
    props.mode === 'fetch' ? labels.fetchCaption : labels.previewCaption,
);
const action = computed(() =>
    props.mode === 'fetch' ? labels.fetch : labels.previewAs,
);
</script>

<template>
    <component
        :is="mode === 'fetch' ? SectionCard : 'section'"
        v-bind="
            mode === 'fetch'
                ? { title, caption, icon: Users }
                : {
                      class: 'flex flex-col gap-3 rounded-lg border border-border-default bg-surface-card p-4',
                  }
        "
        :data-test="`as-user-${mode}`"
    >
        <template v-if="mode === 'preview'">
            <h3 class="type-title-sm text-text-primary">{{ title }}</h3>
            <p class="type-caption text-text-muted">{{ caption }}</p>
        </template>

        <div class="grid gap-3">
            <div class="grid max-w-md gap-1.5">
                <Label :for="searchId">{{ labels.search }}</Label>
                <Input
                    :id="searchId"
                    v-model="search"
                    type="search"
                    maxlength="100"
                    autocomplete="off"
                    :disabled="!mayPreview"
                    data-test="as-user-search"
                    @input="onSearch"
                    @keydown.enter.prevent="loadMembers"
                />
            </div>
            <div class="grid max-w-md gap-1.5">
                <Label :for="memberId">{{ labels.member }}</Label>
                <NativeSelect
                    :id="memberId"
                    v-model="chosen"
                    :disabled="!mayPreview"
                    data-test="as-user-member"
                >
                    <option value="">{{ labels.choose }}</option>
                    <option
                        v-for="member in members ?? []"
                        :key="member.membership_id"
                        :value="member.membership_id"
                    >
                        {{ member.name }} ({{ member.email
                        }}{{
                            member.groups.length > 0
                                ? ` · ${member.groups.join(', ')}`
                                : ''
                        }})
                    </option>
                </NativeSelect>
                <p
                    v-if="members !== null && members.length === 0"
                    class="type-caption text-text-muted"
                    data-test="as-user-none"
                >
                    {{ labels.none }}
                </p>
                <p
                    v-if="membersFailed"
                    role="alert"
                    class="type-caption text-error-text"
                    data-test="as-user-members-failed"
                >
                    {{ labels.membersFailed }}
                </p>
            </div>

            <div class="flex flex-col items-start gap-1">
                <Button
                    type="button"
                    variant="secondary"
                    :blocked="blocked !== undefined || running"
                    :blocked-reason="blocked"
                    :aria-describedby="
                        running ? statusId : blocked ? reasonId : undefined
                    "
                    data-test="as-user-run"
                    @click="run"
                >
                    {{
                        state.wait !== null
                            ? labels.waitFor(state.wait)
                            : hasResult
                              ? labels.refetch
                              : action
                    }}
                </Button>
                <BlockedReason
                    v-if="blocked && !running"
                    :id="reasonId"
                    data-test="as-user-reason"
                    >{{ blocked }}</BlockedReason
                >
            </div>
            <p class="type-caption text-text-muted">{{ labels.audited }}</p>

            <p
                v-if="running"
                :id="statusId"
                role="status"
                class="type-body-sm text-text-secondary"
                data-test="as-user-running"
            >
                {{ labels.running(chosenMember?.name ?? state.name) }}
            </p>
            <p
                v-if="state.refusal"
                role="alert"
                class="type-body-sm text-error-text"
                data-test="as-user-refusal"
            >
                {{ state.refusal }}
            </p>

            <FetchErrorCard
                v-if="state.phase === 'failure' && state.failure"
                ref="card"
                :code="state.failure.code"
                :source="sourceName"
                :heading="labels.failedTitle"
                :status="state.failure.status"
                :request-id="state.failure.requestId"
                :host="state.failure.host"
                :reason="state.failure.reason"
                :missing="state.failure.missing"
                @retry="run"
            />

            <p
                v-if="state.phase === 'expired' || state.phase === 'stale'"
                role="status"
                class="type-body-sm text-warning-text"
                data-test="as-user-expired"
            >
                {{ state.phase === 'stale' ? labels.stale : labels.expired }}
            </p>
            <p
                v-if="mode === 'fetch' && state.ended && !hasResult"
                role="status"
                class="type-body-sm text-warning-text"
                data-test="as-user-ended"
            >
                {{ labels.ended }}
            </p>
            <p
                v-if="earlier"
                class="type-body-sm text-warning-text"
                data-test="as-user-earlier"
            >
                {{ labels.earlier }}
            </p>
        </div>

        <div
            v-if="hasResult && state.shape"
            class="grid gap-3"
            data-test="as-user-result"
        >
            <SegmentedControl
                v-if="mode === 'preview'"
                :model-value="state.using ? 'user' : 'shared'"
                :options="useOptions"
                :label="labels.source"
                data-test="as-user-source"
                @update:model-value="setSource"
            />
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span
                    class="type-body-sm font-semibold text-text-primary"
                    data-test="as-user-name"
                    >{{ labels.previewingAs(state.name) }}</span
                >
                <span
                    class="type-caption rounded-sm bg-info-soft px-2 text-info-strong"
                    data-test="as-user-badge"
                    >{{ labels.sampleData }}</span
                >
                <span class="type-caption text-text-secondary tabular-nums">{{
                    labels.done(state.name, until)
                }}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    data-test="as-user-clear"
                    @click="clear"
                    >{{ labels.useShared }}</Button
                >
            </div>
            <template v-if="mode === 'fetch'">
                <p class="type-caption text-text-muted">
                    {{ labels.structureNote }}
                </p>
                <SampleTree :shape="state.shape" />
            </template>
        </div>
    </component>
</template>
