<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { announce } from '@/lib/announce';

// A top-bar control whose feature ships later: disabled with its reason (UX-DR-22). It stays focusable,
// keeps `aria-disabled`, shows the reason in a tooltip and describes itself by it. Activating it only
// announces the reason.
const props = defineProps<{
    id: string;
    icon: LucideIcon;
    label: string;
    reason: string;
    shortcut?: string;
}>();

function blocked(event: Event): void {
    event.preventDefault();
    announce(props.reason);
}
</script>

<template>
    <Tooltip>
        <TooltipTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-9 text-text-muted aria-disabled:pointer-events-auto"
                aria-disabled="true"
                :aria-describedby="`${id}-reason`"
                :data-test="id"
                @click="blocked"
            >
                <component :is="icon" class="size-[18px]" aria-hidden="true" />
                <span class="sr-only"
                    >{{ label
                    }}<template v-if="shortcut"> {{ shortcut }}</template></span
                >
            </Button>
        </TooltipTrigger>
        <TooltipContent>{{ reason }}</TooltipContent>
    </Tooltip>
    <span :id="`${id}-reason`" class="sr-only">{{ reason }}</span>
</template>
