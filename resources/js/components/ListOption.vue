<script setup lang="ts">
// A popover list option (UX-DR-75, 144). The keyboard-active option gets the 2px inset ring;
// the chosen option gets a leading check (or a 3px left bar in trees). The two are distinct.
withDefaults(
    defineProps<{ active?: boolean; selected?: boolean; tree?: boolean }>(),
    { active: false, selected: false, tree: false },
);
</script>

<template>
    <div
        role="option"
        data-slot="list-option"
        :aria-selected="selected"
        :data-active="active ? 'true' : undefined"
        :data-tree="tree ? 'true' : undefined"
        :class="[
            'type-body-sm flex min-h-(--df-target-chrome) cursor-default items-center gap-2 rounded-sm px-2 py-1 select-none',
            {
                'listbox-option-active': active,
                'listbox-option-selected': selected,
            },
        ]"
    >
        <slot />
    </div>
</template>
