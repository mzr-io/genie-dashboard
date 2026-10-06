<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';

type Props = {
    user: User;
    showEmail?: boolean;
    // A second line under the name (the role), shown instead of the email.
    detail?: string | null;
};

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
    detail: null,
});

const { getInitials } = useInitials();

// Compute whether we should show the avatar image
const showAvatar = computed(
    () => props.user.avatar && props.user.avatar !== '',
);
</script>

<template>
    <!-- User avatars are circles; Workspace avatars are rounded squares, so the two are never confused (UX-DR-9). -->
    <Avatar class="size-8 shrink-0 overflow-hidden rounded-full">
        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
        <AvatarFallback
            class="rounded-full bg-surface-muted font-semibold text-text-primary"
        >
            {{ getInitials(user.name) }}
        </AvatarFallback>
    </Avatar>

    <div
        class="grid min-w-0 flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden"
    >
        <span class="type-title-sm truncate text-text-primary">{{
            user.name
        }}</span>
        <span
            v-if="detail"
            class="type-caption truncate text-text-muted"
            data-slot="user-role"
            >{{ detail }}</span
        >
        <span
            v-else-if="showEmail"
            class="type-caption truncate text-text-muted"
            >{{ user.email }}</span
        >
    </div>
</template>
