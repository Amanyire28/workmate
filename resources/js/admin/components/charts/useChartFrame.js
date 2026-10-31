import { ref, onMounted, onBeforeUnmount } from 'vue';

/**
 * Measures the chart container (charts draw in real pixels so text never stretches)
 * and tracks the hovered/focused index, shared by pointer and keyboard.
 */
export function useChartFrame(count) {
    const wrap = ref(null);
    const width = ref(0);
    const active = ref(null);
    const keyboard = ref(false);
    let observer = null;

    onMounted(() => {
        width.value = wrap.value?.clientWidth || 0;
        if ('ResizeObserver' in window) {
            observer = new ResizeObserver(([entry]) => { width.value = Math.floor(entry.contentRect.width); });
            observer.observe(wrap.value);
        }
    });
    onBeforeUnmount(() => observer?.disconnect());

    function onKeydown(e) {
        const n = count();
        if (!n) return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
            e.preventDefault();
            keyboard.value = true;
            const cur = active.value ?? (e.key === 'ArrowRight' ? -1 : n);
            active.value = Math.min(n - 1, Math.max(0, cur + (e.key === 'ArrowRight' ? 1 : -1)));
        } else if (e.key === 'Home' || e.key === 'End') {
            e.preventDefault();
            keyboard.value = true;
            active.value = e.key === 'Home' ? 0 : n - 1;
        } else if (e.key === 'Escape') {
            active.value = null;
        }
    }
    function onFocus() {
        keyboard.value = true;
        if (active.value == null && count()) active.value = count() - 1;
    }
    function clear() {
        active.value = null;
        keyboard.value = false;
    }

    return { wrap, width, active, keyboard, onKeydown, onFocus, clear };
}
