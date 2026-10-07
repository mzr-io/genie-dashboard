<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { groupLabels as labels } from '@/locales/labels';

// "Create group" (Story 1.23): it expands the inline create form, so it reports whether the form is open and which
// region it controls. The Groups view shows it in the page header, or in the empty state when there are no groups.
defineProps<{ expanded?: boolean; controls?: string }>();

const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <Button
        type="button"
        data-test="create-group"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-controls="expanded ? controls : undefined"
        @click="emit('open')"
    >
        {{ labels.create }}
    </Button>
</template>
