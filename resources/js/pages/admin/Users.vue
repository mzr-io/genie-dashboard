<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Ban, CircleCheck, Mail } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
} from 'vue';
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import InviteUserForm from '@/components/InviteUserForm.vue';
import MemberAccessEditor from '@/components/MemberAccessEditor.vue';
import MemberAttributesEditor from '@/components/MemberAttributesEditor.vue';
import InviteUserLink from '@/components/InviteUserLink.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import Tag from '@/components/Tag.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useShell } from '@/composables/useShell';
import { announce, announceDebounced } from '@/lib/announce';
import { formatDateTime } from '@/lib/format';
import {
    fetchMembers,
    InvitationRequestError,
    memberKey,
    MembersRequestError,
    MemberUpdateError,
    resendInvitation,
    revokeInvitation,
    setMemberStatus,
} from '@/lib/members';
import type { MemberStatusAction } from '@/lib/members';
import type { Member, MembersMeta, MemberSortKey } from '@/lib/members';
import { groups as groupsPage } from '@/routes/admin/users';
import { SIGN_IN_URL } from '@/lib/session';
import {
    accessLabels,
    groupLabels,
    inviteLabels,
    shellPages,
    statusLabels,
    userAttributeLabels,
    userListLabels as labels,
} from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// User configuration (Story 1.20): the Workspace's members and pending invitations in a sortable, searchable
// data table paged by cursor. The server owns the rows, the sort whitelist and the page-size cap; the page
// shows the generic list states (UX-DR-263): the toolbar stays while 5 skeleton rows load or a failure row
// with Retry shows, `list-empty` with "Invite user", and `list-no-match` with Clear search. The Groups column
// lists each member's groups (Story 1.23; "No groups" when none) and "Groups" opens the Groups view. "Invite user" expands the inline invite form (Story 1.21); an
// Invited row carries Resend and Revoke.
const { t } = useI18n();
const { can, membershipId } = useShell();
const toasts = useToasts();
const config = shellPages['user-configuration'];
const SEARCH_DEBOUNCE_MS = 300;

type Pending =
    | { kind: 'count' }
    | { kind: 'page' }
    | { kind: 'sort'; text: string }
    | null;

const search = ref('');
const appliedSearch = ref('');
const sortKey = ref<MemberSortKey>('name');
const direction = ref<'asc' | 'desc'>('asc');
// Cursor of every page visited, so Previous is a step back (the server pages forward only).
const cursors = ref<(string | null)[]>([null]);

const members = ref<Member[]>([]);
const meta = ref<MembersMeta | null>(null);
const state = ref<'loading' | 'error' | 'forbidden' | 'ready'>('loading');
const refreshing = ref(false);
const searchId = useId();
const inviting = ref(false);
const inviteRegionId = useId();
const busyRows = ref<string[]>([]);
const heldPermissions = computed(() =>
    Object.keys(can.value).filter((key) => can.value[key] === true),
);
// The member whose Roles & permissions editor is expanded under its row (Story 1.22), if any.
const editing = ref<string | null>(null);
// The member whose Attributes editor is expanded under its row (Story 2.12), if any: only one editor is open at a time.
const attributing = ref<string | null>(null);
// Deactivate and Reactivate (Story 1.24): the member awaiting confirmation, the row highlighted after a save and the
// inline reasons of refused changes (409 and 403), by row key.
const confirming = ref<Member | null>(null);
const confirmOpen = ref(false);
const confirmInvoker = ref<HTMLElement | null>(null);
const highlighted = ref<string | null>(null);
const rowReasons = ref<Record<string, string>>({});

let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
// What to announce once the load that was just started succeeds.
let pending: Pending = null;

const page = computed(() => cursors.value.length);
const total = computed(() => meta.value?.total ?? 0);
const matched = computed(() => meta.value?.matched ?? 0);
const searching = computed(() => appliedSearch.value.trim() !== '');
const hasNext = computed(() => meta.value?.next_cursor != null);
const paged = computed(() => page.value > 1 || hasNext.value);
// No member and no invitation at all (not merely no match).
const isEmpty = computed(() => state.value === 'ready' && total.value === 0);
const showToolbar = computed(
    () => !isEmpty.value && state.value !== 'forbidden',
);

const columns: DataTableColumn[] = [
    {
        key: 'name',
        label: labels.columns.name,
        sortable: true,
        rowHeader: true,
    },
    { key: 'email', label: labels.columns.email, sortable: true },
    { key: 'role', label: labels.columns.role, sortable: true },
    { key: 'status', label: labels.columns.status, sortable: true },
    { key: 'groups', label: labels.columns.groups },
    {
        key: 'last_active',
        label: labels.columns.last_active,
        sortable: true,
        class: 'tabular-nums',
    },
    { key: 'actions', label: labels.actions },
];

const statusIcons: Record<Member['status'], Component> = {
    active: CircleCheck,
    invited: Mail,
    deactivated: Ban,
};

function fail(error: unknown): void {
    if (
        error instanceof MembersRequestError &&
        (error.status === 401 || error.status === 419)
    ) {
        // The session is gone: sign in again.
        window.location.assign(SIGN_IN_URL);

        return;
    }

    pending = null;
    state.value =
        error instanceof MembersRequestError && error.status === 403
            ? 'forbidden'
            : 'error';
    refreshing.value = false;
}

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    // A cold load or a retry shows the skeleton; later loads keep the table and mark it busy.
    if (meta.value === null || state.value !== 'ready') {
        state.value = 'loading';
    } else {
        refreshing.value = true;
    }

    try {
        const result = await fetchMembers(
            {
                q: appliedSearch.value,
                sort: sortKey.value,
                direction: direction.value,
                cursor: cursors.value[cursors.value.length - 1] ?? null,
            },
            mine.signal,
        );

        if (mine.signal.aborted) {
            return;
        }

        // A later page that comes back empty while rows exist (the list shrank): back to the first page.
        if (
            result.data.length === 0 &&
            result.meta.matched > 0 &&
            cursors.value.length > 1
        ) {
            cursors.value = [null];
            await load();

            return;
        }

        members.value = result.data;
        meta.value = result.meta;
        state.value = 'ready';
        refreshing.value = false;

        const done = pending;
        pending = null;

        if (done?.kind === 'count') {
            announceDebounced(
                'user-list-count',
                labels.count(result.meta.matched, result.meta.total),
            );
        } else if (done?.kind === 'page') {
            announce(labels.pageChanged(page.value, result.data.length));
        } else if (done?.kind === 'sort') {
            announce(done.text);
        }
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        fail(error);
    }
}

function applySearch(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    if (search.value.trim() === appliedSearch.value.trim()) {
        return;
    }

    highlighted.value = null;
    appliedSearch.value = search.value;
    cursors.value = [null];
    pending = { kind: 'count' };
    void load();
}

function onSearchInput(): void {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(applySearch, SEARCH_DEBOUNCE_MS);
}

function clearSearch(): void {
    search.value = '';
    applySearch();
    document.getElementById(searchId)?.focus();
}

function sortBy(key: string): void {
    const next = key as MemberSortKey;

    if (sortKey.value === next) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = next;
        direction.value = 'asc';
    }

    highlighted.value = null;
    cursors.value = [null];
    // Spoken only once the new order has arrived.
    pending = {
        kind: 'sort',
        text: labels.sorted(labels.columns[next], direction.value === 'desc'),
    };
    void load();
}

function nextPage(): void {
    const cursor = meta.value?.next_cursor;

    if (!cursor) {
        return;
    }

    highlighted.value = null;
    cursors.value = [...cursors.value, cursor];
    pending = { kind: 'page' };
    void load();
}

function previousPage(): void {
    if (cursors.value.length < 2) {
        return;
    }

    highlighted.value = null;
    cursors.value = cursors.value.slice(0, -1);
    pending = { kind: 'page' };
    void load();
}

function clearHighlight(key: string): void {
    if (highlighted.value === key) {
        highlighted.value = null;
    }
}

function toggleInvite(): void {
    inviting.value = !inviting.value;
}

function closeInvite(): void {
    inviting.value = false;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>('[data-test="invite-user"]')
            ?.focus(),
    );
}

// An invitation was created, re-sent or saved without its email: show it (the first page, current order).
function invited(): void {
    cursors.value = [null];
    void load();
}

// Why a member's access cannot be edited here (shown beside a disabled Edit), or null when it can. The person's own
// membership ID comes from the server; while it is unknown the editor fails closed.
function editBlock(row: Member): string | null {
    if (
        membershipId.value === null ||
        row.membership_id === membershipId.value
    ) {
        return accessLabels.selfReason;
    }

    return row.status === 'active' ? null : accessLabels.inactiveReason;
}

function toggleEdit(row: Member): void {
    const key = memberKey(row);

    highlighted.value = null;
    attributing.value = null;
    editing.value = editing.value === key ? null : key;
}

// The Attributes action (Story 2.12): `users.manage`, on members other than the Admin's own row (fails closed while the
// person's own membership ID is unknown).
function attributesAllowed(row: Member): boolean {
    return (
        row.kind === 'member' &&
        can.value['users.manage'] === true &&
        membershipId.value !== null &&
        row.membership_id !== membershipId.value
    );
}

function toggleAttributes(row: Member): void {
    const key = memberKey(row);

    highlighted.value = null;
    editing.value = null;
    attributing.value = attributing.value === key ? null : key;
}

function closeAttributes(row: Member): void {
    attributing.value = null;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(
                `[data-test="edit-attributes"][data-member="${memberKey(row)}"]`,
            )
            ?.focus(),
    );
}

function attributesGone(): void {
    attributing.value = null;
    toasts.add({ kind: 'error', message: userAttributeLabels.memberGone });
    void load();
}

function closeEditor(row: Member): void {
    editing.value = null;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(
                `[data-test="edit-access"][data-member="${memberKey(row)}"]`,
            )
            ?.focus(),
    );
}

// The member is gone from the Workspace: close the editor and reload the list.
function memberGone(): void {
    editing.value = null;
    toasts.add({ kind: 'error', message: accessLabels.gone });
    void load();
}

// The server's new state of a member: the row shows it at once and the editor stays open on it.
function accessSaved(updated: Member): void {
    members.value = members.value.map((row) =>
        memberKey(row) === memberKey(updated) ? { ...row, ...updated } : row,
    );
}

// Why Deactivate or Reactivate is not offered on a row, or null when it is: never on the Admin's own row (and not
// while the person's own membership ID is unknown, which fails closed).
function statusBlocked(row: Member): boolean {
    return (
        membershipId.value === null || row.membership_id === membershipId.value
    );
}

function askDeactivate(row: Member, event: Event): void {
    confirming.value = row;
    confirmInvoker.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    confirmOpen.value = true;
}

// A row key inside an attribute selector.
function esc(key: string): string {
    return typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
        ? CSS.escape(key)
        : key.replace(/["\\]/g, '\\$&');
}

function rowElement(key: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(`[data-row-key="${esc(key)}"]`);
}

function rowFallback(row: Member): HTMLElement | null {
    const key = esc(memberKey(row));

    return document.querySelector<HTMLElement>(
        `[data-test="status-action"][data-member="${key}"], [data-row-key="${key}"]`,
    );
}

// The inline reason of a refused change (409, 403 and 429), or null for any other failure.
function reasonFor(error: MemberUpdateError): string | null {
    if (error.code === 'access.last_users_manage_holder') {
        return statusLabels.lastHolder;
    }

    if (error.code === 'access.self_change_forbidden') {
        return statusLabels.self;
    }

    if (error.code === 'access.revision_conflict') {
        return statusLabels.conflict;
    }

    if (error.status === 429) {
        return t('throttled');
    }

    return error.status === 403 ? statusLabels.forbidden : null;
}

// Rewrites one row of the CURRENT list by key (the list may have been reloaded since the click).
function patchRow(key: string, changes: Partial<Member>): void {
    members.value = members.value.map((item) =>
        memberKey(item) === key ? { ...item, ...changes } : item,
    );
}

// Applies the change to the row at once, then asks the server. On success the list refreshes, the row is highlighted
// and focused (when it is still listed) and `saved` is announced politely; on failure the row rolls back and a rollback
// toast (an alert, never auto-dismissed) appears, with the reason inline for a 409, 403 or 429.
async function changeStatus(
    row: Member,
    action: MemberStatusAction,
): Promise<void> {
    const key = memberKey(row);
    const id = row.membership_id;
    const name = row.name || row.email;

    if (isBusy(row)) {
        return;
    }

    highlighted.value = null;

    if (!id || row.revision === undefined) {
        toasts.add({
            kind: 'rollback',
            message: statusLabels.rollback(action, name),
        });

        return;
    }

    const previous = row.status;
    const next: Member['status'] =
        action === 'deactivate' ? 'deactivated' : 'active';

    busyRows.value = [...busyRows.value, key];
    const reasons = { ...rowReasons.value };
    delete reasons[key];
    rowReasons.value = reasons;
    patchRow(key, { status: next });

    try {
        const updated = await setMemberStatus(id, action, row.revision);

        patchRow(key, {
            status: next,
            revision: updated.revision ?? row.revision + 1,
        });
    } catch (error) {
        if (
            error instanceof MemberUpdateError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        // Back to the previous state, in place, on the list as it is now.
        patchRow(key, { status: previous });
        const failure = error instanceof MemberUpdateError ? error : null;
        const reason = failure ? reasonFor(failure) : null;

        if (reason !== null) {
            rowReasons.value = { ...rowReasons.value, [key]: reason };
        }

        toasts.add({
            kind: 'rollback',
            message:
                failure?.status === 404
                    ? statusLabels.gone
                    : failure?.status === 429
                      ? t('throttled')
                      : statusLabels.rollback(action, name),
        });

        // The server's truth: a stale revision, a vanished member or a lost authority.
        if (
            failure !== null &&
            (failure.status === 404 ||
                failure.code === 'access.revision_conflict' ||
                failure.code === 'access.not_authorized')
        ) {
            await load();
        }

        return;
    } finally {
        busyRows.value = busyRows.value.filter((item) => item !== key);
    }

    // Saved, whether or not the refresh below succeeds.
    announce(
        `${action === 'deactivate' ? statusLabels.deactivated(name) : statusLabels.reactivated(name)} ${t('saved')}`,
        'polite',
    );
    await load();
    await nextTick();

    const element = rowElement(key);

    // Highlight and focus only a row that is still listed after the refresh.
    if (element !== null) {
        highlighted.value = key;
        await nextTick();
        rowElement(key)?.focus();
    }
}

function confirmDeactivate(): void {
    const row = confirming.value;

    if (row) {
        void changeStatus(row, 'deactivate');
    }
}

function isBusy(row: Member): boolean {
    return busyRows.value.includes(memberKey(row));
}

async function rowAction(
    row: Member,
    action: (id: string) => Promise<unknown>,
    done: string,
): Promise<void> {
    const id = row.invitation_id;

    if (!id || isBusy(row)) {
        return;
    }

    highlighted.value = null;
    busyRows.value = [...busyRows.value, memberKey(row)];

    try {
        await action(id);
        announce(done, 'polite');
        await load();
    } catch (error) {
        if (
            error instanceof InvitationRequestError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        // The row is as the server left it; say why the action did not happen.
        const status =
            error instanceof InvitationRequestError ? error.status : 0;
        const code =
            error instanceof InvitationRequestError ? error.code : null;
        const message =
            code === 'access.permission_not_held'
                ? inviteLabels.resendNotHeld
                : status === 429
                  ? t('throttled')
                  : status === 404
                    ? inviteLabels.gone
                    : t('save-failed.form');

        toasts.add({ kind: 'error', message });
        await load();
    } finally {
        busyRows.value = busyRows.value.filter((key) => key !== memberKey(row));
    }
}

function resend(row: Member): Promise<void> {
    return rowAction(row, resendInvitation, labels.resent(row.email));
}

function revoke(row: Member): Promise<void> {
    return rowAction(row, revokeInvitation, labels.revoked(row.email));
}

onMounted(() => {
    void load();
});

onBeforeUnmount(() => {
    controller?.abort();

    if (timer !== null) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <Head :title="config.title" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="config.title">
            <Button as-child variant="secondary" data-test="open-groups">
                <Link :href="groupsPage().url">{{
                    groupLabels.openGroups
                }}</Link>
            </Button>
            <!-- In the empty state the action sits in the empty region instead. -->
            <InviteUserLink
                v-if="!isEmpty"
                :expanded="inviting"
                :controls="inviteRegionId"
                @open="toggleInvite"
            />
        </PageHeader>

        <div v-if="inviting" :id="inviteRegionId">
            <InviteUserForm
                :held="heldPermissions"
                @changed="invited"
                @close="closeInvite"
            />
        </div>

        <div
            v-if="showToolbar"
            role="search"
            :aria-label="labels.toolbar"
            data-slot="toolbar"
            class="flex flex-wrap items-center gap-3"
        >
            <label :for="searchId" class="sr-only">{{ labels.search }}</label>
            <Input
                :id="searchId"
                v-model="search"
                type="search"
                maxlength="100"
                autocomplete="off"
                class="max-w-xs"
                :placeholder="labels.searchPlaceholder"
                data-test="search"
                @input="onSearchInput"
                @keydown.enter.prevent="applySearch"
            />
            <p
                v-if="searching && meta"
                class="type-caption text-text-muted tabular-nums"
                data-test="count"
            >
                {{ labels.count(matched, total) }}
            </p>
        </div>

        <ListStates
            v-if="state === 'loading'"
            state="loading"
            :items="config.items"
            :action="config.action"
            :toolbar="false"
        />

        <p
            v-else-if="state === 'forbidden'"
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>

        <ListStates
            v-else-if="state === 'error'"
            state="error"
            :items="config.items"
            :action="config.action"
            @retry="load"
        />

        <ListStates
            v-else-if="isEmpty"
            state="empty"
            :items="config.items"
            :action="config.action"
        >
            <InviteUserLink
                :expanded="inviting"
                :controls="inviteRegionId"
                @open="toggleInvite"
            />
        </ListStates>

        <section
            v-else-if="searching && matched === 0"
            data-slot="list-no-match"
            class="flex flex-col items-center gap-4 rounded-lg border border-border-default bg-surface-card p-6"
        >
            <p class="type-body-md text-center text-text-secondary">
                {{
                    t('list-no-match', {
                        items: config.items,
                        query: appliedSearch.trim(),
                    })
                }}
            </p>
            <Button
                type="button"
                variant="secondary"
                data-test="clear-search"
                @click="clearSearch"
            >
                {{ labels.clearSearch }}
            </Button>
        </section>

        <template v-else>
            <DataTable
                :caption="labels.caption"
                :label="labels.tableRegion"
                :columns="columns"
                :rows="members"
                :row-key="memberKey"
                :sort-key="sortKey"
                :sort-direction="direction"
                :busy="refreshing"
                :expanded="editing ?? attributing"
                :highlighted="highlighted"
                @row-blur="clearHighlight"
                @sort="sortBy"
            >
                <template #detail="{ row }">
                    <MemberAttributesEditor
                        v-if="attributing === memberKey(row)"
                        :key="`attributes-${memberKey(row)}`"
                        :member="row"
                        @gone="attributesGone"
                        @close="closeAttributes(row)"
                    />
                    <MemberAccessEditor
                        v-else
                        :key="memberKey(row)"
                        :member="row"
                        :held="heldPermissions"
                        @saved="accessSaved"
                        @gone="memberGone"
                        @close="closeEditor(row)"
                    />
                </template>
                <template #cell-name="{ row }">
                    <template v-if="row.name">{{ row.name }}</template>
                    <template v-else>
                        <span aria-hidden="true">{{ labels.emptyCell }}</span>
                        <span class="sr-only">{{ labels.noName }}</span>
                    </template>
                </template>
                <template #cell-role="{ row }">
                    {{ labels.roles[row.role] }}
                </template>
                <template #cell-status="{ row }">
                    <Tag
                        :tone="row.status === 'invited' ? 'info' : 'neutral'"
                        class="gap-1.5 py-0.5"
                        :data-status="row.status"
                    >
                        <component
                            :is="statusIcons[row.status]"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ labels.statuses[row.status] }}
                    </Tag>
                </template>
                <template #cell-groups="{ row }">
                    <template v-if="row.groups.length > 0">{{
                        row.groups.map((group) => group.name).join(', ')
                    }}</template>
                    <span v-else class="text-text-muted">{{
                        labels.noGroups
                    }}</span>
                </template>
                <template #cell-last_active="{ row }">
                    <time
                        v-if="row.last_active_at"
                        :datetime="row.last_active_at"
                        >{{ formatDateTime(row.last_active_at) }}</time
                    >
                    <template v-else>
                        <span aria-hidden="true">{{ labels.emptyCell }}</span>
                        <span class="sr-only">{{ labels.noLastActive }}</span>
                    </template>
                </template>
                <template #cell-actions="{ row }">
                    <span
                        v-if="row.kind === 'member'"
                        class="flex flex-col items-start gap-1"
                    >
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            :blocked="editBlock(row) !== null"
                            :blocked-reason="editBlock(row) ?? undefined"
                            :aria-expanded="
                                editing === memberKey(row) ? 'true' : 'false'
                            "
                            :aria-label="
                                editing === memberKey(row)
                                    ? accessLabels.closeFor(
                                          row.name || row.email,
                                      )
                                    : accessLabels.editFor(
                                          row.name || row.email,
                                      )
                            "
                            :aria-controls="
                                editing === memberKey(row)
                                    ? `detail-${memberKey(row)}`
                                    : undefined
                            "
                            :aria-describedby="
                                editBlock(row) !== null
                                    ? `own-${memberKey(row)}`
                                    : undefined
                            "
                            :data-member="memberKey(row)"
                            data-test="edit-access"
                            @click="toggleEdit(row)"
                        >
                            {{ accessLabels.edit }}
                        </Button>
                        <p
                            v-if="editBlock(row) !== null"
                            :id="`own-${memberKey(row)}`"
                            class="type-caption text-text-secondary"
                        >
                            {{ editBlock(row) }}
                        </p>
                        <Button
                            v-if="attributesAllowed(row)"
                            type="button"
                            variant="secondary"
                            size="sm"
                            :aria-expanded="
                                attributing === memberKey(row)
                                    ? 'true'
                                    : 'false'
                            "
                            :aria-label="
                                attributing === memberKey(row)
                                    ? userAttributeLabels.closeFor(
                                          row.name || row.email,
                                      )
                                    : userAttributeLabels.attributesFor(
                                          row.name || row.email,
                                      )
                            "
                            :aria-controls="
                                attributing === memberKey(row)
                                    ? `detail-${memberKey(row)}`
                                    : undefined
                            "
                            :data-member="memberKey(row)"
                            data-test="edit-attributes"
                            @click="toggleAttributes(row)"
                        >
                            {{ userAttributeLabels.attributes }}
                        </Button>
                        <Button
                            v-if="
                                !statusBlocked(row) && row.status === 'active'
                            "
                            type="button"
                            variant="destructive-soft"
                            size="sm"
                            :disabled="isBusy(row)"
                            :aria-label="
                                statusLabels.deactivateFor(
                                    row.name || row.email,
                                )
                            "
                            :aria-describedby="
                                rowReasons[memberKey(row)]
                                    ? `reason-${memberKey(row)}`
                                    : undefined
                            "
                            :data-member="memberKey(row)"
                            data-test="status-action"
                            @click="askDeactivate(row, $event)"
                        >
                            {{ statusLabels.deactivate }}
                        </Button>
                        <Button
                            v-else-if="
                                !statusBlocked(row) &&
                                row.status === 'deactivated'
                            "
                            type="button"
                            variant="secondary"
                            size="sm"
                            :disabled="isBusy(row)"
                            :aria-label="
                                statusLabels.reactivateFor(
                                    row.name || row.email,
                                )
                            "
                            :aria-describedby="
                                rowReasons[memberKey(row)]
                                    ? `reason-${memberKey(row)}`
                                    : undefined
                            "
                            :data-member="memberKey(row)"
                            data-test="status-action"
                            @click="changeStatus(row, 'reactivate')"
                        >
                            {{ statusLabels.reactivate }}
                        </Button>
                        <p
                            v-if="rowReasons[memberKey(row)]"
                            :id="`reason-${memberKey(row)}`"
                            role="alert"
                            class="type-caption text-text-secondary"
                            data-test="status-reason"
                        >
                            {{ rowReasons[memberKey(row)] }}
                        </p>
                    </span>
                    <span
                        v-if="row.kind === 'invitation'"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            :disabled="isBusy(row)"
                            :aria-label="labels.resendFor(row.email)"
                            data-test="resend"
                            @click="resend(row)"
                        >
                            {{ labels.resend }}
                        </Button>
                        <Button
                            type="button"
                            variant="destructive-soft"
                            size="sm"
                            :disabled="isBusy(row)"
                            :aria-label="labels.revokeFor(row.email)"
                            data-test="revoke"
                            @click="revoke(row)"
                        >
                            {{ labels.revoke }}
                        </Button>
                    </span>
                </template>
            </DataTable>

            <nav
                v-if="paged"
                :aria-label="labels.pagination"
                class="flex items-center justify-between gap-3"
                data-slot="pagination"
            >
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :disabled="page < 2 || refreshing"
                    data-test="previous"
                    @click="previousPage"
                >
                    {{ labels.previous }}
                </Button>
                <span class="type-caption text-text-muted tabular-nums">{{
                    labels.pageNumber(page)
                }}</span>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :disabled="!hasNext || refreshing"
                    data-test="next"
                    @click="nextPage"
                >
                    {{ labels.next }}
                </Button>
            </nav>
        </template>

        <ConfirmDialog
            v-if="confirming"
            v-model:open="confirmOpen"
            :title="
                statusLabels.dialogTitle(confirming.name || confirming.email)
            "
            :description="
                statusLabels.dialogImpact(confirming.name || confirming.email)
            "
            :object-name="confirming.name || confirming.email"
            :verb="statusLabels.dialogVerb"
            :invoker="confirmInvoker"
            :fallback="() => (confirming ? rowFallback(confirming) : null)"
            @confirm="confirmDeactivate"
        />
    </div>
</template>
