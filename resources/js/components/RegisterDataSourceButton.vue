<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { dataSourceLabels as labels } from '@/locales/labels';
import { create } from '@/routes/admin/data-sources';

// The register button (Story 2.3): the Data sources page's one primary button. It opens the form page, so it is a
// link styled as a button. The page shows it in the header, or in the empty state when there are none yet.
</script>

<template>
    <Button as-child>
        <Link :href="create().url" data-test="register">{{
            labels.register
        }}</Link>
    </Button>
</template>
