import { ref } from 'vue';

// Whether the Add-blocks Panel is open (Story 4.7). Shared, because opening it on a wide screen collapses the sidebar to its icon rail and closing it
// restores the sidebar (UX-DR-152): the shell reads this, the dashboard sets it.
const open = ref(false);

export function useAddBlocksPanel() {
    return {
        open,
        show: () => {
            open.value = true;
        },
        hide: () => {
            open.value = false;
        },
    };
}
