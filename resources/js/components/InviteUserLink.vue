<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { userListLabels as labels } from '@/locales/labels';

// "Invite user" (Story 1.21): enabled for an Admin with `users.manage` (the page itself is behind that gate). It
// expands the inline invite form, so it reports whether the form is open and which region it controls.
defineProps<{ expanded?: boolean; controls?: string }>();

const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <Button
        type="button"
        data-test="invite-user"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-controls="expanded ? controls : undefined"
        @click="emit('open')"
    >
        {{ labels.invite }}
    </Button>
</template>
