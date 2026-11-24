import { watch, nextTick, onBeforeUnmount } from 'vue';

/**
 * What every modal (bottom sheet, dialog, drawer) shares, so they behave the same and nest correctly.
 *
 *  - Background scrolling is locked while any modal is open and restored only when the LAST one closes, at the original scroll position.
 *    (Locking is done by fixing the page in place, which also stops iOS Safari from scrolling the page behind a sheet.)
 *  - Only the topmost modal reacts to Escape and Tab; focus is trapped inside it and goes back to where it was when it closes.
 *  - The page's visible area (which shrinks when a phone keyboard opens) is published as --wm-vvh and --wm-vvt on <html>, so a modal can
 *    size itself to what is really visible and keep its action buttons above the keyboard. Nothing here touches form values.
 *  - Everything is undone when the modal closes or its component is destroyed.
 */
const stack = [];          // open modals, oldest first
let lockDepth = 0;
let saved = null;
let vvBound = false;

const FOCUSABLE = 'button:not(:disabled), [href], input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])';

function publishViewport() {
    const vv = window.visualViewport;
    const root = document.documentElement.style;
    root.setProperty('--wm-vvh', `${Math.round(vv ? vv.height : window.innerHeight)}px`);
    root.setProperty('--wm-vvt', `${Math.round(vv ? vv.offsetTop : 0)}px`);
}

function lock() {
    if (lockDepth++ > 0) return;
    const html = document.documentElement;
    const body = document.body;
    const scrollbar = Math.max(window.innerWidth - html.clientWidth, 0);
    saved = {
        y: window.scrollY, x: window.scrollX,
        htmlOverflow: html.style.overflow,
        position: body.style.position, top: body.style.top, left: body.style.left, right: body.style.right, width: body.style.width, paddingRight: body.style.paddingRight,
    };
    html.style.overflow = 'hidden';
    body.style.position = 'fixed';
    body.style.top = `-${saved.y}px`;
    body.style.left = '0';
    body.style.right = '0';
    body.style.width = '100%';
    if (scrollbar > 0) body.style.paddingRight = `${scrollbar}px`;   // no sideways jump on desktop
    publishViewport();
    if (window.visualViewport && !vvBound) {
        window.visualViewport.addEventListener('resize', publishViewport);
        window.visualViewport.addEventListener('scroll', publishViewport);
        vvBound = true;
    }
    window.addEventListener('resize', publishViewport);
}

function unlock() {
    if (lockDepth === 0 || --lockDepth > 0) return;
    const html = document.documentElement;
    const body = document.body;
    window.removeEventListener('resize', publishViewport);
    if (window.visualViewport && vvBound) {
        window.visualViewport.removeEventListener('resize', publishViewport);
        window.visualViewport.removeEventListener('scroll', publishViewport);
        vvBound = false;
    }
    html.style.removeProperty('--wm-vvh');
    html.style.removeProperty('--wm-vvt');
    if (saved) {
        html.style.overflow = saved.htmlOverflow;
        body.style.position = saved.position;
        body.style.top = saved.top;
        body.style.left = saved.left;
        body.style.right = saved.right;
        body.style.width = saved.width;
        body.style.paddingRight = saved.paddingRight;
        window.scrollTo(saved.x, saved.y);
        saved = null;
    }
}

function onKeydown(e) {
    const top = stack[stack.length - 1];
    if (!top) return;
    if (e.key === 'Escape') {
        // An open information bubble closes first; the modal stays
        if (e.defaultPrevented || document.querySelector('[data-wm-tip]')) return;
        e.preventDefault();
        top.close();
    } else if (e.key === 'Tab') {
        const panel = top.panel();
        if (!panel) return;
        const items = Array.from(panel.querySelectorAll(FOCUSABLE)).filter((el) => el.offsetParent !== null || el === document.activeElement);
        if (!items.length) { e.preventDefault(); panel.focus(); return; }
        const first = items[0];
        const last = items[items.length - 1];
        const active = document.activeElement;
        if (!panel.contains(active)) { e.preventDefault(); first.focus(); }
        else if (e.shiftKey && (active === first || active === panel)) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
    }
}

function register(entry) {
    if (stack.length === 0) document.addEventListener('keydown', onKeydown);
    stack.push(entry);
    lock();
}

function unregister(entry) {
    const i = stack.indexOf(entry);
    if (i === -1) return;
    stack.splice(i, 1);
    if (stack.length === 0) document.removeEventListener('keydown', onKeydown);
    unlock();
}

/**
 * @param {{ open: import('vue').Ref<boolean>|(() => boolean), panel: import('vue').Ref<HTMLElement|null>, close: () => void, focus?: () => (HTMLElement|null) }} options
 *   open:  whether the modal is showing (a ref or a getter)
 *   panel: the dialog element
 *   close: what Escape should do
 *   focus: optional, the element to focus first (default: the panel itself)
 */
export function useModalBehaviour({ open, panel, close, focus }) {
    const entry = { panel: () => (panel && 'value' in panel ? panel.value : panel), close };
    let returnFocusTo = null;
    let active = false;

    const isOpen = typeof open === 'function' ? open : () => open.value;

    function begin() {
        if (active) return;
        active = true;
        returnFocusTo = document.activeElement;
        register(entry);
    }
    function end() {
        if (!active) return;
        active = false;
        unregister(entry);
        const el = returnFocusTo;
        returnFocusTo = null;
        if (el && el.isConnected && typeof el.focus === 'function') el.focus({ preventScroll: true });
    }

    watch(isOpen, async (now) => {
        if (now) {
            begin();
            await nextTick();
            const target = (focus && focus()) || entry.panel();
            if (target) target.focus({ preventScroll: true });
        } else {
            end();
        }
    }, { immediate: true, flush: 'post' });

    onBeforeUnmount(end);

    return { end };
}
