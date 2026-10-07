<script setup lang="ts">
import { overlayLabels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// "Skip to content" is the first focusable element on every page; "Go to notifications" follows
// while a toast shows (UX-DR-270).
const toasts = useToasts();

function focusTarget(event: Event, selector: string): void {
    const target = document.querySelector<HTMLElement>(selector);

    if (!target) {
        return;
    }

    event.preventDefault();

    if (!target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
    }

    target.focus();
}

const link =
    'type-body-sm sr-only rounded-md bg-surface-card px-3 py-2 font-semibold text-text-primary shadow-md focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70]';
</script>

<template>
    <nav data-slot="skip-links" :aria-label="overlayLabels.skipLinks">
        <a
            href="#main-content"
            data-slot="skip-link"
            :class="link"
            @click="focusTarget($event, '#main-content')"
        >
            {{ overlayLabels.skipToContent }}
        </a>
        <a
            v-if="toasts.visible"
            href="#notifications"
            data-slot="skip-link-notifications"
            :class="link"
            @click="focusTarget($event, '#notifications')"
        >
            {{ overlayLabels.goToNotifications }}
        </a>
    </nav>
</template>
