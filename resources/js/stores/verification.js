import { reactive } from 'vue';

// Plain deep copy (structuredClone can't copy Vue's reactive proxies)
const copy = (x) => JSON.parse(JSON.stringify(x));
import { DEFAULT_VERSIONS } from '@/data/verificationRequirements.js';

/**
 * Document requirement versions for the admin's Document Requirements screen.
 *
 * PROTOTYPE: versions live in this browser tab's memory and aren't saved.
 * Providers' documents themselves are real and go through the Laravel API
 * (GET /api/v1/me/verification); see pages/GetVerifiedPage.vue.
 */
export const verification = reactive({
    versions: structuredClone(DEFAULT_VERSIONS),
});

export function currentVersion(professionId) {
    const list = verification.versions[professionId] || [];
    // Categories without sample requirements start with nothing published
    return list[list.length - 1] || { version: 0, publishedAt: null, publishedBy: null, note: null, documents: [] };
}

export function versionHistory(professionId) {
    return [...(verification.versions[professionId] || [])].reverse();
}

/** Publish a new requirements version. Earlier versions (and submitted snapshots) stay unchanged. */
export function publishVersion(professionId, documents, { by, note }) {
    const list = (verification.versions[professionId] ||= []);
    const version = { version: list.length + 1, publishedAt: new Date().toISOString(), publishedBy: by, note, documents: copy(documents) };
    list.push(version);
    return version;
}
