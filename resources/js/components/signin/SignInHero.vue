<script setup lang="ts">
import { Activity, ShieldCheck, Sparkles } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { signInLabels as labels } from '@/locales/labels';

// The dark sign-in hero (UX-DR-90). The only dark surface in the MVP; every colour is a hero token.
// The illustration is decorative, so it is hidden from assistive technology.
</script>

<template>
    <section
        data-slot="signin-hero"
        :aria-label="labels.heroIllustration"
        class="relative hidden overflow-hidden bg-hero-surface text-hero-text lg:flex lg:w-[55%] lg:flex-col lg:justify-between lg:p-12"
    >
        <svg
            aria-hidden="true"
            focusable="false"
            class="pointer-events-none absolute -right-40 -bottom-40 size-[640px] text-hero-surface-raised"
            viewBox="0 0 640 640"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
        >
            <circle cx="320" cy="320" r="120" />
            <circle cx="320" cy="320" r="200" />
            <circle cx="320" cy="320" r="280" />
            <circle cx="320" cy="320" r="318" />
        </svg>

        <header class="relative flex items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <AppLogoIcon />
                <span class="type-title-md font-bold">{{
                    labels.product
                }}</span>
            </div>
            <span class="type-body-sm text-hero-text-muted">{{
                labels.productDescriptor
            }}</span>
        </header>

        <div class="relative flex flex-col gap-6">
            <p
                class="type-label-caps inline-flex w-fit items-center gap-2 rounded-full bg-hero-chip px-3 py-1.5 text-accent-ink-inverse"
            >
                <Sparkles class="size-4" aria-hidden="true" />
                {{ labels.tagline }}
            </p>
            <p class="type-display-hero max-w-xl text-hero-text">
                {{ labels.headline }}
            </p>
            <p class="type-body-md max-w-lg text-hero-text-muted">
                {{ labels.heroBody }}
            </p>

            <div
                aria-hidden="true"
                data-slot="signin-illustration"
                class="grid max-w-lg grid-cols-3 gap-3"
            >
                <div
                    class="col-span-2 flex h-28 flex-col justify-end gap-2 rounded-lg border border-hero-border bg-hero-card p-4"
                >
                    <div class="h-2 w-1/3 rounded-full bg-hero-border" />
                    <div class="flex items-end gap-2">
                        <div class="h-6 w-4 rounded-xs bg-brand" />
                        <div class="h-10 w-4 rounded-xs bg-brand" />
                        <div class="h-8 w-4 rounded-xs bg-brand" />
                        <div class="h-12 w-4 rounded-xs bg-brand" />
                    </div>
                </div>
                <div
                    class="flex h-28 flex-col justify-end gap-2 rounded-lg border border-hero-border bg-hero-card p-4"
                >
                    <div class="h-2 w-1/2 rounded-full bg-hero-border" />
                    <div class="h-3 w-3/4 rounded-xs bg-brand" />
                </div>
                <div
                    class="flex h-20 flex-col justify-end gap-2 rounded-lg border border-hero-border bg-hero-card p-4"
                >
                    <div class="h-2 w-2/3 rounded-full bg-hero-border" />
                    <div class="h-2 w-1/2 rounded-xs bg-brand" />
                </div>
                <div
                    class="col-span-2 flex h-20 flex-col justify-end gap-2 rounded-lg border border-hero-border bg-hero-card p-4"
                >
                    <div class="h-2 w-1/4 rounded-full bg-hero-border" />
                    <div class="h-3 w-2/3 rounded-xs bg-brand" />
                </div>
            </div>

            <ul class="flex flex-col gap-3">
                <li class="type-body-md flex items-center gap-3">
                    <ShieldCheck
                        class="size-5 text-accent-ink-inverse"
                        aria-hidden="true"
                    />
                    {{ labels.trustSecurity }}
                </li>
                <li class="type-body-md flex items-center gap-3">
                    <Activity
                        class="size-5 text-accent-ink-inverse"
                        aria-hidden="true"
                    />
                    {{ labels.trustLive }}
                </li>
            </ul>
        </div>

        <footer class="type-caption relative text-hero-text-muted">
            {{ labels.footer }}
        </footer>
    </section>
</template>
