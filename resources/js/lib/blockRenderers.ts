import { defineAsyncComponent } from 'vue';
import type { Component } from 'vue';

// The client side of the Block Type registry (Story 4.5, AD-21): a Block Type key to its Renderer, loaded when a Block of that type is first drawn.
// Every Renderer implements every state and interpolates slots as literal text; a type with no Renderer is drawn as unavailable, never as nothing.
export const blockRenderers: Record<string, Component> = {
    'kpi-card': defineAsyncComponent(
        () => import('@block-types/kpi-card/v1/Renderer.vue'),
    ),
    'line-area': defineAsyncComponent(
        () => import('@block-types/line-area/v1/Renderer.vue'),
    ),
};

export const rendererFor = (type: string): Component | null =>
    blockRenderers[type] ?? null;
