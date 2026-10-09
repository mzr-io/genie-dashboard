<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { endpointLabels as labels } from '@/locales/labels';

// "+ Add endpoint" (Story 2.9): the Endpoints tab's one primary button. The page shows it in the header, or in the empty
// state when there are no Endpoints yet, and opens the form on click.
defineEmits<{ click: [] }>();
</script>

<template>
    <Button type="button" data-test="add-endpoint" @click="$emit('click')">
        {{ labels.add }}
    </Button>
</template>
