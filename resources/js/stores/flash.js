import { reactive } from 'vue';

/**
 * A short confirmation or problem shown at the bottom of the screen for a few
 * seconds ("Link copied", "Post published"). Shown by AppStatusLayer.
 */
export const flash = reactive({ message: '', tone: 'success', id: 0 });

let timer = null;

export function showFlash(message, tone = 'success', ms = 3500) {
    flash.message = message;
    flash.tone = tone;
    flash.id++;
    clearTimeout(timer);
    timer = setTimeout(() => { flash.message = ''; }, ms);
}

export function hideFlash() {
    clearTimeout(timer);
    flash.message = '';
}
