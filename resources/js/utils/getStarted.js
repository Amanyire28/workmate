/**
 * The answer to "What do you want to do first?" after signing up, kept on
 * this device. Someone who said they're here to hire isn't invited to offer
 * services on the home page again.
 */
const KEY = 'wm-get-started';

export function rememberGetStarted(choice) {
    try { localStorage.setItem(KEY, choice); } catch { /* storage blocked: the home page may invite them again */ }
}

export function answeredHire() {
    try { return localStorage.getItem(KEY) === 'hire'; } catch { return false; }
}
