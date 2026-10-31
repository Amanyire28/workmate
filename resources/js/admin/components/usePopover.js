import { ref, onMounted, onBeforeUnmount } from 'vue';

/** Open/close state for a menu button + panel: closes on outside click and Escape. */
export function usePopover() {
    const open = ref(false);
    const root = ref(null);

    function onDocPointer(e) {
        if (open.value && root.value && !root.value.contains(e.target)) open.value = false;
    }
    function onKey(e) {
        if (e.key === 'Escape' && open.value) {
            open.value = false;
            root.value?.querySelector('[aria-haspopup]')?.focus();
        }
    }
    onMounted(() => {
        document.addEventListener('pointerdown', onDocPointer);
        document.addEventListener('keydown', onKey);
    });
    onBeforeUnmount(() => {
        document.removeEventListener('pointerdown', onDocPointer);
        document.removeEventListener('keydown', onKey);
    });

    return { open, root, toggle: () => { open.value = !open.value; }, close: () => { open.value = false; } };
}
