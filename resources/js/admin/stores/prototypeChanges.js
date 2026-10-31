import { reactive } from 'vue';
import { currentRole } from '../permissions.js';
import { auth } from '@/stores/auth.js';

/**
 * Changes made in the admin PROTOTYPE: approvals, suspensions, edits, settings.
 *
 * They live only in this browser tab's memory. Nothing is sent to a server, nothing
 * persists after a reload, and no customer or provider is affected. Every change is
 * still recorded (record history + the Audit Logs page) so the intended workflow
 * can be reviewed before the Laravel actions exist.
 */
export const patches = reactive({});    // moduleId → { rowId → partial row }
export const created = reactive({});    // moduleId → [rows]
export const settings = reactive({});   // moduleId → saved values
export const sessionAudit = reactive([]);
export const toasts = reactive([]);

let seq = 0;

function actorLabel() {
    return `${auth.user?.name || "Administrator"} (${currentRole().label})`;
}

function historyEntry(label, reason, detail) {
    return { at: new Date().toISOString(), actor: actorLabel(), label, reason: reason || null, detail: detail || null, session: true };
}

export function actorName() {
    return actorLabel();
}

/** Record an action that isn't a record edit (document viewed, rules published…) */
export function recordAudit(action, summary, target, reason) {
    audit(action, summary, target, reason);
}

function audit(action, summary, target, reason) {
    sessionAudit.unshift({
        id: `AUD-S${String(++seq).padStart(4, '0')}`, action, summary, actor: actorLabel(), target,
        reason: reason || '—', at: new Date().toISOString(), ip: 'This browser', source: 'session', history: [],
    });
}

/** Apply an action's changes to a record, with history and an audit entry */
export function recordChange(moduleId, row, { patch = {}, label, reason, detail, action }) {
    const bucket = (patches[moduleId] ||= {});
    const prev = bucket[row.id] || {};
    const history = [...(prev.history || row.history || []), historyEntry(label, reason, detail)];
    bucket[row.id] = { ...prev, ...patch, history };
    audit(action || `${moduleId}.${label.toLowerCase().replace(/\s+/g, '_')}`, `${label}: ${row.name || row.title || row.id}`, row.id, reason);
}

export function recordCreate(moduleId, row, label) {
    (created[moduleId] ||= []).unshift({ ...row, history: [historyEntry(label)], sessionCreated: true });
    audit(`${moduleId}.created`, `${label}: ${row.name || row.title || row.id}`, row.id);
}

export function recordSettings(moduleId, values, summary, reason) {
    settings[moduleId] = { ...values };
    audit(`${moduleId}.updated`, summary, `setting:${moduleId}`, reason);
}

export function toast(message, tone = 'info') {
    const id = ++seq;
    // Keep it readable: only the three most recent stay on screen
    if (toasts.length >= 3) toasts.splice(0, toasts.length - 2);
    toasts.push({ id, message, tone });
    setTimeout(() => {
        const i = toasts.findIndex(t => t.id === id);
        if (i >= 0) toasts.splice(i, 1);
    }, 6000);
}
