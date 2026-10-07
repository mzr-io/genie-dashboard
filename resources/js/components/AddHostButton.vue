<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { hostAllowlistLabels as labels } from '@/locales/labels';

// "+ Add host" (Story 2.1): it expands the inline add form, so it reports whether the form is open and which region
// it controls. The Host allowlist shows it in the page header, or in the empty state when there are no hosts.
defineProps<{ expanded?: boolean; controls?: string }>();

const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <Button
        type="button"
        data-test="add-host"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-controls="expanded ? controls : undefined"
        @click="emit('open')"
    >
        {{ labels.add }}
    </Button>
</template>
