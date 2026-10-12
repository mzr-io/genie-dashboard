<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { WIZARD_URL } from '@/lib/draftBlocks';
import { draftBlockLabels as labels } from '@/locales/labels';

// The create button (Story 3.3): the Draft blocks page's one primary button. It opens the wizard page, so it is a link styled
// as a button. The page shows it in the header, or in the empty state when there are no Drafts yet.
</script>

<template>
    <Button as-child>
        <Link :href="WIZARD_URL" data-test="create-block">{{
            labels.create
        }}</Link>
    </Button>
</template>
