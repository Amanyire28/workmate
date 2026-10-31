import { reactive } from 'vue';
import { defaultRuleSet, PREVIOUS_RULESET } from '../utils/blueTick.js';

// Plain deep copy (structuredClone can't copy Vue's reactive proxies)
const copy = (x) => JSON.parse(JSON.stringify(x));

/**
 * Blue tick rule versions (prototype, this browser tab only).
 * The last entry in `versions` is the published rule set; `draft` holds unpublished
 * edits. Publishing appends a new version, so every past award can be traced to
 * the exact rules that applied.
 */
export const blueTickRules = reactive({
    versions: [PREVIOUS_RULESET, defaultRuleSet()],
    draft: null,          // { ruleset, savedAt, savedBy }
});

export const publishedRules = () => blueTickRules.versions[blueTickRules.versions.length - 1];
export const previousRules = () => blueTickRules.versions[blueTickRules.versions.length - 2];

export function saveDraft(ruleset, by) {
    blueTickRules.draft = { ruleset: copy(ruleset), savedAt: new Date().toISOString(), savedBy: by };
}

export function discardDraft() {
    blueTickRules.draft = null;
}

export function publish(ruleset, { by, note }) {
    const next = { ...copy(ruleset), version: publishedRules().version + 1, publishedAt: new Date().toISOString(), publishedBy: by, note };
    blueTickRules.versions.push(next);
    blueTickRules.draft = null;
    return next;
}
