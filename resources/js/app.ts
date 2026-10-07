import { createInertiaApp } from '@inertiajs/vue3';
import { createPinia } from 'pinia';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { createCatalogue } from '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.use(createPinia());
        app.use(createCatalogue());
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: 'var(--df-progress-bar)',
    },
});

// This will listen for flash toast data from the server...
initializeFlashToast();
