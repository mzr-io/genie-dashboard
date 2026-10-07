<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import SignInHero from '@/components/signin/SignInHero.vue';
import SkipLink from '@/components/SkipLink.vue';
import ToastRegion from '@/components/ToastRegion.vue';
import { initAnnouncer } from '@/lib/announce';
import { signInLabels as labels } from '@/locales/labels';

// Split screen (UX-DR-158): the dark hero at about 55% on the left above 1024 px, the form on the right.
// Below 1024 px the hero is hidden and the form is centred under the logo.
initAnnouncer();
</script>

<template>
    <SkipLink />
    <div class="flex min-h-dvh bg-surface-card">
        <SignInHero />
        <main
            id="main-content"
            tabindex="-1"
            class="flex min-w-0 flex-1 items-center justify-center px-4 py-8 sm:px-8"
        >
            <div class="flex w-full max-w-md flex-col gap-8">
                <div class="flex items-center justify-center gap-2 lg:hidden">
                    <AppLogoIcon />
                    <span class="type-title-md font-bold text-text-primary">{{
                        labels.product
                    }}</span>
                </div>
                <slot />
            </div>
        </main>
    </div>
    <ToastRegion />
</template>
