<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { userAttributeLabels as labels } from '@/locales/labels';

// "+ Add attribute" (Story 2.12): it expands the inline add form, so it reports whether the form is open and which
// region it controls. User attributes shows it in the page header, or in the empty state when nothing is defined.
defineProps<{ expanded?: boolean; controls?: string }>();

const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <Button
        type="button"
        data-test="add-attribute"
        :aria-expanded="expanded ? 'true' : 'false'"
        :aria-controls="expanded ? controls : undefined"
        @click="emit('open')"
    >
        {{ labels.action }}
    </Button>
</template>
