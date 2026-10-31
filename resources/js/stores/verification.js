import { reactive } from 'vue';

// Plain deep copy (structuredClone can't copy Vue's reactive proxies)
const copy = (x) => JSON.parse(JSON.stringify(x));
import { DEFAULT_VERSIONS, applicableDocuments, isRequired } from '@/data/verificationRequirements.js';

/**
 * Verification state shared by the provider "Get Verified" flow and the admin
 * prototype, so a requirement published in the admin is what providers see, and a
 * provider's submission appears in the admin review queue.
 *
 * PROTOTYPE: everything lives in this browser tab's memory. Selected files never
 * leave the device: they are previewed with local object URLs and are gone after a
 * reload. In production, requirements and applications come from the Laravel API
 * and files go to private storage through authenticated, validated uploads.
 */
export const verification = reactive({
    versions: structuredClone(DEFAULT_VERSIONS),
    submissions: [],   // applications submitted from the provider flow in this session
});

export function currentVersion(professionId) {
    const list = verification.versions[professionId] || [];
    return list[list.length - 1] || null;
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

let seq = 900;

/**
 * Submit an application. Refuses unless every required document has the right
 * number of valid files (and an expiry date where one is required).
 */
export function submitApplication({ professionId, providerName, area, answers, uploads }) {
    const snapshot = copy(currentVersion(professionId));
    const docs = applicableDocuments(snapshot, answers);
    const missing = docs.filter(d => isRequired(d, answers) && !uploadComplete(d, uploads[d.id]));
    if (missing.length) throw new Error(`Still needed: ${missing.map(d => d.name).join(', ')}`);

    const now = new Date().toISOString();
    const app = {
        id: `VA-${String(++seq).padStart(5, '0')}`,
        source: 'provider_session',
        providerId: 'P-SESSION', providerName, profession: professionId, area,
        submittedAt: now, lastActionAt: now, status: 'pending_review', reviewer: null,
        answers: { ...answers },
        snapshot: { version: snapshot.version, publishedAt: snapshot.publishedAt },
        documents: docs.map(d => {
            const u = uploads[d.id];
            const has = u && u.files.length;
            return {
                reqId: d.id, name: d.name, requirement: isRequired(d, answers) ? 'required' : 'optional', instructions: d.instructions,
                expiryRequired: d.expiry.required,
                files: has ? u.files.map(f => ({ name: f.name, type: f.type, sizeKb: Math.max(1, Math.round(f.size / 1024)), side: f.side || null, url: f.url })) : [],
                expiresAt: u?.expiresAt || null,
                status: has ? 'awaiting_review' : 'not_uploaded', note: null, reviewedBy: null, reviewedAt: null,
            };
        }),
        history: [{ at: now, actor: providerName, label: 'Application submitted', reason: null, session: true }],
    };
    verification.submissions.unshift(app);
    return app;
}

/** Replace a rejected document's files from the provider side */
export function replaceDocument(appId, reqId, upload) {
    const app = verification.submissions.find(a => a.id === appId);
    const doc = app?.documents.find(d => d.reqId === reqId);
    if (!doc) return;
    doc.files = upload.files.map(f => ({ name: f.name, type: f.type, sizeKb: Math.max(1, Math.round(f.size / 1024)), side: f.side || null, url: f.url }));
    doc.expiresAt = upload.expiresAt || null;
    doc.status = 'awaiting_review';
    doc.note = null;
    app.lastActionAt = new Date().toISOString();
    if (app.status === 'info_requested') app.status = 'under_review';
    app.history.push({ at: app.lastActionAt, actor: app.providerName, label: `Replacement uploaded: ${doc.name}`, session: true });
}

/** All files present (count, front/back) and a valid expiry date when required */
export function uploadComplete(doc, upload) {
    if (!upload) return false;
    const needed = doc.frontBack ? 2 : 1;
    if (upload.files.length < needed) return false;
    if (doc.expiry.required && expiryProblem(doc, upload.expiresAt)) return false;
    return true;
}

/** Why an expiry date isn't acceptable, or null when it is */
export function expiryProblem(doc, expiresAt) {
    if (!doc.expiry.required) return null;
    if (!expiresAt) return 'Enter the expiry date shown on the document.';
    const minDate = new Date();
    minDate.setMonth(minDate.getMonth() + (doc.expiry.minMonthsValid || 0));
    if (new Date(expiresAt) < minDate) {
        return doc.expiry.minMonthsValid ? `Must be valid for at least ${doc.expiry.minMonthsValid} more month${doc.expiry.minMonthsValid > 1 ? 's' : ''}.` : 'This document has expired.';
    }
    return null;
}
