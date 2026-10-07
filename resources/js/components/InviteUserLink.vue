<script setup lang="ts">
import { useId } from 'vue';
import BlockedReason from '@/components/BlockedReason.vue';
import { Button } from '@/components/ui/button';
import { announce } from '@/lib/announce';
import { userListLabels as labels } from '@/locales/labels';

// "Invite user" until Story 1.21: a link that stays focusable and `aria-disabled`, with its reason inline.
// Enter and Space announce the reason like a click and never scroll or navigate.
const reasonId = useId();

function blocked(event: Event): void {
    event.preventDefault();
    announce(labels.inviteReason);
}
</script>

<template>
    <span class="inline-flex flex-col items-center gap-1">
        <Button as-child blocked :blocked-reason="labels.inviteReason">
            <a
                role="link"
                tabindex="0"
                aria-disabled="true"
                data-test="invite-user"
                :aria-describedby="reasonId"
                @click="blocked"
                @keydown.enter="blocked"
                @keydown.space="blocked"
            >
                {{ labels.invite }}
            </a>
        </Button>
        <BlockedReason :id="reasonId">{{ labels.inviteReason }}</BlockedReason>
    </span>
</template>
