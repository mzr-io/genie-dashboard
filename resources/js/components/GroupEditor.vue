<script setup lang="ts">
import { Ban } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import Tag from '@/components/Tag.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce, announceDebounced } from '@/lib/announce';
import {
    addGroupMember,
    deleteGroup,
    GroupRequestError,
    removeGroupMember,
    renameGroup,
} from '@/lib/groups';
import type { Group, GroupMember } from '@/lib/groups';
import { fetchMembers } from '@/lib/members';
import type { Member } from '@/lib/members';
import { SIGN_IN_URL } from '@/lib/session';
import { groupLabels as labels } from '@/locales/labels';

// The inline editor of one group (Story 1.23; UX-DR-261, 263, 271): it expands in the table under the row, never as a
// modal. Rename, the members with Remove (a deactivated member keeps their place and shows "Deactivated"), a member
// search with Add, and Delete behind an alertdialog that names the group and its member count (focus on Cancel).
// Changes are announced politely; the server's row replaces the page's copy after each one.
const props = defineProps<{ group: Group }>();

const emit = defineEmits<{
    // The server's new state of the group (renamed, member added or removed).
    changed: [group: Group];
    deleted: [group: Group];
    // The group is no longer in the Workspace: the page reloads.
    gone: [];
    close: [];
}>();

const { t } = useI18n();
const SEARCH_DEBOUNCE_MS = 300;

const name = ref(props.group.name);
const nameError = ref<string | null>(null);
const failure = ref<'save' | 'throttled' | null>(null);
const failureRef = ref<HTMLElement | null>(null);
const heading = ref<HTMLElement | null>(null);
const processing = ref(false);
const confirming = ref(false);
const nameId = useId();
const searchId = useId();

const search = ref('');
const results = ref<Member[]>([]);
const searchState = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
let timer: ReturnType<typeof setTimeout> | null = null;
let controller: AbortController | null = null;

// A group reloaded by the page shows its latest name, unless the name field holds a draft.
watch(
    () => props.group.name,
    (next, previous) => {
        // A draft the person is typing stays; only an untouched field follows the group.
        if (!processing.value && name.value === previous) {
            name.value = next;
        }
    },
);

const label = (member: { name: string; email: string }) =>
    member.name || member.email;

function inGroup(membershipId: string | undefined): boolean {
    return props.group.members.some(
        (member) => member.membership_id === membershipId,
    );
}

async function focusFailure(): Promise<void> {
    await nextTick();
    failureRef.value?.focus();
}

// Returns true when the error was handled here (the caller stops).
async function refused(error: unknown): Promise<void> {
    if (!(error instanceof GroupRequestError)) {
        failure.value = 'save';
        await focusFailure();

        return;
    }

    if (error.status === 401 || error.status === 419) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    if (error.code === 'access.not_authorized') {
        window.location.reload();

        return;
    }

    if (error.status === 404) {
        emit('gone');

        return;
    }

    if (error.status === 429) {
        failure.value = 'throttled';
    } else {
        failure.value = 'save';
    }

    await focusFailure();
}

async function run(work: () => Promise<void>): Promise<void> {
    if (processing.value) {
        return;
    }

    failure.value = null;
    processing.value = true;

    try {
        await work();
    } catch (error) {
        await refused(error);
    } finally {
        processing.value = false;
    }
}

function validName(): boolean {
    const trimmed = name.value.trim();

    if (trimmed === '') {
        nameError.value = labels.nameRequired;
    } else if ([...trimmed].length > 64) {
        nameError.value = labels.nameTooLong;
    } else {
        nameError.value = null;
    }

    if (nameError.value !== null) {
        document.getElementById(nameId)?.focus();
    }

    return nameError.value === null;
}

async function saveName(): Promise<void> {
    if (!validName()) {
        return;
    }

    if (name.value.trim() === props.group.name) {
        announce(labels.unchanged, 'polite');

        return;
    }

    await run(async () => {
        try {
            const group = await renameGroup(
                props.group.group_id,
                name.value.trim(),
            );

            name.value = group.name;
            announce(`${t('saved')} ${labels.renamed(group.name)}`, 'polite');
            emit('changed', group);
        } catch (error) {
            if (error instanceof GroupRequestError && error.status === 422) {
                nameError.value = error.errors.name?.length
                    ? error.reason === 'name_taken'
                        ? labels.nameTaken
                        : labels.nameInvalid
                    : labels.nameInvalid;
                await nextTick();
                document.getElementById(nameId)?.focus();

                return;
            }

            throw error;
        }
    });
}

async function add(member: Member): Promise<void> {
    const id = member.membership_id;

    if (!id) {
        return;
    }

    let added = false;

    await run(async () => {
        const group = await addGroupMember(props.group.group_id, id);

        announce(labels.added(label(member), group.name), 'polite');
        emit('changed', group);
        added = true;
    });

    // The buttons are enabled again: focus goes to the added member's Remove button.
    if (added) {
        await nextTick();
        document
            .querySelector<HTMLElement>(
                `[data-test="group-remove"][data-member="${id}"]`,
            )
            ?.focus();
    }
}

async function remove(member: GroupMember): Promise<void> {
    await run(async () => {
        const group = await removeGroupMember(
            props.group.group_id,
            member.membership_id,
        );

        announce(labels.removed(label(member), group.name), 'polite');
        emit('changed', group);
        // The removed row is gone: focus returns to the editor's title.
        await nextTick();
        heading.value?.focus();
    });
}

async function confirmDelete(): Promise<void> {
    await run(async () => {
        await deleteGroup(props.group.group_id);
        announce(labels.deleted(props.group.name), 'polite');
        emit('deleted', props.group);
    });
}

async function runSearch(): Promise<void> {
    controller?.abort();
    const query = search.value.trim();

    if (query === '') {
        results.value = [];
        searchState.value = 'idle';

        return;
    }

    controller = new AbortController();
    const mine = controller;
    searchState.value = 'loading';

    try {
        // Members already in the group are not offered, so keep reading the following pages until someone can be
        // added or the matches run out.
        let cursor: string | null = null;
        let found: Member[] = [];

        do {
            const page = await fetchMembers(
                { q: query, sort: 'name', direction: 'asc', cursor },
                mine.signal,
            );

            if (mine.signal.aborted) {
                return;
            }

            // Pending invitations have no membership to add.
            found = [
                ...found,
                ...page.data.filter(
                    (row) => row.kind === 'member' && row.membership_id,
                ),
            ];
            cursor = page.meta.next_cursor;
        } while (
            cursor !== null &&
            found.every((row) => inGroup(row.membership_id))
        );

        results.value = found;
        searchState.value = 'ready';
        announceDebounced(
            'group-member-search',
            labels.searchResults(candidates().length),
        );
    } catch {
        if (!mine.signal.aborted) {
            searchState.value = 'error';
        }
    }
}

function onSearchInput(): void {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(() => void runSearch(), SEARCH_DEBOUNCE_MS);
}

function candidates(): Member[] {
    return results.value.filter((row) => !inGroup(row.membership_id));
}

onMounted(() => heading.value?.focus());

onBeforeUnmount(() => {
    controller?.abort();

    if (timer !== null) {
        clearTimeout(timer);
    }
});

defineExpose({ focus: () => heading.value?.focus() });
</script>

<template>
    <section
        :aria-label="labels.editorRegion(group.name)"
        data-slot="group-editor"
        class="grid max-w-2xl gap-6"
    >
        <h3
            ref="heading"
            tabindex="-1"
            class="type-title-md text-text-primary"
            data-test="group-title"
        >
            {{ labels.editorTitle(group.name) }}
        </h3>

        <div
            v-if="failure"
            ref="failureRef"
            tabindex="-1"
            role="alert"
            class="rounded-md border-l-[3px] border-error bg-error-soft p-3"
            data-test="group-failure"
        >
            <p class="type-body-sm text-error-text">
                <template v-if="failure === 'throttled'">{{
                    t('throttled')
                }}</template>
                <template v-else>{{ t('save-failed.form') }}</template>
            </p>
        </div>

        <form
            class="flex flex-wrap items-end gap-3"
            novalidate
            data-test="group-rename-form"
            @submit.prevent="saveName"
        >
            <div class="w-full max-w-xs">
                <FormField
                    :id="nameId"
                    :label="labels.rename"
                    :error="nameError"
                    required
                    #default="{ field }"
                >
                    <Input
                        v-bind="field"
                        v-model="name"
                        type="text"
                        maxlength="64"
                        autocomplete="off"
                        data-test="group-rename-name"
                        @input="nameError = null"
                    />
                </FormField>
            </div>
            <Button
                type="submit"
                variant="secondary"
                :disabled="processing"
                data-test="group-rename-save"
            >
                {{ labels.renameSave }}
            </Button>
        </form>

        <div
            class="grid gap-2"
            role="group"
            :aria-label="labels.membersOf(group.name)"
        >
            <h4 class="type-label text-text-primary">
                {{ labels.members }}
                <span class="type-caption text-text-muted tabular-nums"
                    >({{ labels.memberCount(group.member_count) }})</span
                >
            </h4>
            <p class="type-caption text-text-muted">
                {{ labels.deactivatedNote }}
            </p>
            <p
                v-if="group.members.length === 0"
                class="type-body-sm text-text-secondary"
                data-test="group-no-members"
            >
                {{ labels.noMembers }}
            </p>
            <ul v-else class="grid gap-2" data-test="group-members">
                <li
                    v-for="member in group.members"
                    :key="member.membership_id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border-default bg-surface-card px-3 py-2"
                    data-slot="group-member"
                >
                    <span
                        class="type-body-sm flex flex-wrap items-center gap-2"
                    >
                        <span class="text-text-primary">{{
                            member.name || member.email
                        }}</span>
                        <span v-if="member.name" class="text-text-muted">{{
                            member.email
                        }}</span>
                        <Tag
                            v-if="member.status === 'deactivated'"
                            class="gap-1.5 py-0.5"
                            data-status="deactivated"
                        >
                            <Ban class="size-3.5" aria-hidden="true" />
                            {{ labels.deactivated }}
                        </Tag>
                    </span>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="processing"
                        :aria-label="
                            labels.removeFor(label(member), group.name)
                        "
                        data-test="group-remove"
                        :data-member="member.membership_id"
                        @click="remove(member)"
                    >
                        {{ labels.remove }}
                    </Button>
                </li>
            </ul>
        </div>

        <div class="grid gap-2">
            <h4 class="type-label text-text-primary">
                {{ labels.addMembers }}
            </h4>
            <label :for="searchId" class="sr-only">{{
                labels.memberSearch
            }}</label>
            <Input
                :id="searchId"
                v-model="search"
                type="search"
                maxlength="100"
                autocomplete="off"
                class="max-w-xs"
                :placeholder="labels.memberSearchPlaceholder"
                data-test="group-member-search"
                @input="onSearchInput"
                @keydown.enter.prevent="runSearch"
            />
            <p
                v-if="searchState === 'idle'"
                class="type-caption text-text-muted"
            >
                {{ labels.searchHint }}
            </p>
            <p
                v-else-if="searchState === 'error'"
                role="alert"
                class="type-body-sm text-error-text"
                data-test="group-search-error"
            >
                {{ labels.searchFailed }}
            </p>
            <p
                v-else-if="searchState === 'ready' && candidates().length === 0"
                class="type-body-sm text-text-secondary"
                data-test="group-search-none"
            >
                {{ labels.searchNone }}
            </p>
            <ul
                v-else-if="searchState === 'ready'"
                class="grid gap-2"
                data-test="group-candidates"
            >
                <li
                    v-for="member in candidates()"
                    :key="member.membership_id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border-default bg-surface-card px-3 py-2"
                    data-slot="group-candidate"
                >
                    <span
                        class="type-body-sm flex flex-wrap items-center gap-2"
                    >
                        <span class="text-text-primary">{{
                            member.name || member.email
                        }}</span>
                        <span v-if="member.name" class="text-text-muted">{{
                            member.email
                        }}</span>
                        <Tag
                            v-if="member.status === 'deactivated'"
                            class="gap-1.5 py-0.5"
                        >
                            <Ban class="size-3.5" aria-hidden="true" />
                            {{ labels.deactivated }}
                        </Tag>
                    </span>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="processing"
                        :aria-label="labels.addFor(label(member), group.name)"
                        data-test="group-add"
                        @click="add(member)"
                    >
                        {{ labels.add }}
                    </Button>
                </li>
            </ul>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Button
                type="button"
                variant="destructive-soft"
                :disabled="processing"
                data-test="group-delete"
                @click="confirming = true"
            >
                {{ labels.delete }}
            </Button>
            <Button
                type="button"
                variant="secondary"
                data-test="group-close"
                @click="emit('close')"
            >
                {{ labels.close }}
            </Button>
        </div>

        <ConfirmDialog
            v-model:open="confirming"
            :title="labels.deleteTitle(group.name)"
            :description="labels.deleteImpact(group.name, group.member_count)"
            :object-name="labels.deleteObject(group.name)"
            @confirm="confirmDelete"
        />
    </section>
</template>
