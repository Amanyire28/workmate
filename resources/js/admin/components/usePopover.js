import { ref, onMounted, onBeforeUnmount } from 'vue';

/**
 * Open/close state for a menu button + panel: closes on outside click and Escape.
 * `options.also` may return a second element (a panel moved to the end of <body>) that counts as "inside".
 */
export function usePopover(options = {}) {
    const open = ref(false);
    const root = ref(null);

    function inside(target) {
        if (root.value && root.value.contains(target)) return true;
        const extra = options.also ? options.also() : null;
        return !!(extra && extra.contains(target));
    }
    function onDocPointer(e) {
        if (open.value && !inside(e.target)) open.value = false;
    }
    function onKey(e) {
        if (e.key === 'Escape' && open.value) {
            e.preventDefault();   // so a modal underneath knows this Escape was already used
            open.value = false;
            root.value?.querySelector('[aria-haspopup], [aria-expanded]')?.focus();
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
