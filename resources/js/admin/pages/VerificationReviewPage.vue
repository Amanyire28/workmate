<template>
  <div class="flex flex-col gap-4">
    <router-link to="/admin/verification/applications" class="inline-flex items-center gap-1.5 self-start text-[13px] font-medium text-[#64748B] hover:text-ink">
      <i class="bi bi-arrow-left" aria-hidden="true"></i>All applications
    </router-link>

    <div v-if="error" class="rounded-xl wm-surface">
      <StateBlock kind="error" title="This application couldn’t load" :message="error">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="load">Try again</button>
      </StateBlock>
    </div>
    <div v-else-if="loading" class="wm-skeleton h-[420px] !rounded-xl" aria-busy="true"></div>
    <div v-else-if="!app" class="rounded-xl wm-surface">
      <StateBlock kind="empty" title="Application not found" :message="`No application with reference ${route.params.id}.`" />
    </div>

    <template v-else>
      <AdminPageHeader :title="`${app.id} · ${app.providerName}`" :description="`${categoryLabel(app.profession)} · ${app.area} · submitted ${formatDateTime(app.submittedAt)}`" :demo="!isSession">
        <template #actions>
          <AdminStatusBadge :status="app.status" />
          <span v-if="isSession" class="rounded-md bg-[#EFF6FF] px-2 py-1 text-[12px] font-medium text-primary">Submitted in this session</span>
        </template>
      </AdminPageHeader>

      <p v-if="snapshot && current && snapshot.version !== current.version" class="wm-notice wm-notice-info">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <span>Submitted under requirements <strong>v{{ snapshot.version }}</strong>. The current version is v{{ current.version }} ({{ current.note }}). This application is reviewed against v{{ snapshot.version }}, the rules that applied when it was submitted.</span>
      </p>

      <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
        <!-- ============ Documents ============ -->
        <section aria-labelledby="docs-heading" class="flex flex-col gap-3">
          <div class="flex items-baseline justify-between gap-3">
            <h2 id="docs-heading" class="text-[15px] font-semibold text-ink">Required document checklist</h2>
            <p class="text-[12.5px] text-[#64748B]">{{ acceptedRequired }} of {{ requiredDocs.length }} required accepted</p>
          </div>

          <article v-for="d in app.documents" :key="d.reqId" class="rounded-xl border bg-white p-4" :class="d.status === 'accepted' ? 'border-[#BBF7D0]' : 'border-[#E2E8F0]'">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <h3 class="text-[14.5px] font-semibold text-ink">{{ d.name }}</h3>
                <p class="text-[12.5px] text-[#64748B]">{{ d.requirement === 'required' ? 'Required' : 'Optional' }} · {{ d.instructions }}</p>
              </div>
              <AdminStatusBadge :status="docStatus(d)" />
            </div>

            <dl class="mt-3 grid grid-cols-[minmax(110px,auto)_1fr] gap-x-4 gap-y-1.5 text-[13px]">
              <dt class="text-[#64748B]">Files</dt>
              <dd class="text-ink">
                <template v-if="d.files.length">
                  <span v-for="f in d.files" :key="f.name" class="block">
                    {{ f.side ? `${f.side[0].toUpperCase()}${f.side.slice(1)}: ` : '' }}{{ f.name }}
                    <span class="text-[#64748B]">· {{ f.sizeKb }} KB<template v-if="f.pages > 1"> · {{ f.pages }} pages</template></span>
                  </span>
                </template>
                <span v-else class="text-[#94A3B8]">Not uploaded</span>
              </dd>
              <template v-if="d.expiryRequired">
                <dt class="text-[#64748B]">Expires</dt>
                <dd :class="expiryClass(d)">{{ d.expiresAt ? formatDate(d.expiresAt) : 'Not given' }}<template v-if="expiryNote(d)"> · {{ expiryNote(d) }}</template></dd>
              </template>
              <template v-if="d.reviewedBy">
                <dt class="text-[#64748B]">Reviewed</dt>
                <dd class="text-ink">{{ d.reviewedBy }} · {{ formatDateTime(d.reviewedAt) }}</dd>
              </template>
              <template v-if="d.note">
                <dt class="text-[#64748B]">Reviewer note</dt>
                <dd class="text-[#92400E]">{{ d.note }}</dd>
              </template>
            </dl>

            <!-- Secure preview: permission-gated, every opening logged -->
            <div v-if="d.files.length" class="mt-3">
              <button
                v-if="can('documents.view')"
                type="button"
                class="inline-flex items-center gap-1.5 min-h-9 text-[13px] font-medium text-primary hover:underline"
                :aria-expanded="previewOpen === d.reqId"
                @click="togglePreview(d)"
              ><i class="bi bi-file-earmark-lock" aria-hidden="true"></i>{{ previewOpen === d.reqId ? 'Close preview' : 'Open secure preview' }}</button>
              <p v-else class="flex items-center gap-1.5 text-[12.5px] text-[#64748B]"><i class="bi bi-lock" aria-hidden="true"></i>Your role can’t open verification documents.</p>

              <div v-if="previewOpen === d.reqId" class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <figure v-for="f in d.files" :key="f.name" class="overflow-hidden rounded-lg border border-[#E2E8F0] bg-[#0F172A]">
                  <img v-if="f.url && f.type?.startsWith('image/') && f.type !== 'image/heic'" :src="f.url" :alt="`${d.name}${f.side ? ` (${f.side})` : ''}`" class="max-h-64 w-full object-contain" />
                  <div v-else class="flex aspect-[4/3] flex-col items-center justify-center gap-2 p-4 text-center text-white/80">
                    <i class="bi bi-file-earmark-lock text-3xl" aria-hidden="true"></i>
                    <p class="text-[12px]">{{ f.url ? 'PDF file' : 'Sample placeholder: no document is stored in the prototype.' }}</p>
                    <a v-if="f.url" :href="f.url" target="_blank" rel="noopener" class="text-[12px] text-[#93C5FD] underline">Open file</a>
                  </div>
                  <figcaption class="bg-white px-2.5 py-1.5 text-[11.5px] text-[#64748B]">{{ f.name }}</figcaption>
                </figure>
                <p class="sm:col-span-2 text-[11.5px] text-[#64748B]">
                  In production this opens a short-lived signed link from private storage, watermarked with your name. This view was recorded in the audit log.
                </p>
              </div>
            </div>

            <div v-if="canReviewDocs && d.files.length && ['awaiting_review', 'accepted', 'replacement_required'].includes(d.status)" class="mt-3 flex flex-wrap gap-2 border-t border-[#F1F5F9] pt-3">
              <button v-if="d.status !== 'accepted'" type="button" class="wm-btn wm-btn-primary !min-h-9 !rounded-lg !px-3 !text-[13px]" :disabled="d.status === 'replacement_required'" @click="acceptDoc(d)">
                <i class="bi bi-check2" aria-hidden="true"></i>Accept
              </button>
              <button type="button" class="wm-btn !min-h-9 !rounded-lg !px-3 !text-[13px] border-[#FCA5A5] bg-white text-[#B91C1C] hover:bg-[#FEF2F2]" @click="rejectDoc(d)">
                <i class="bi bi-x-lg" aria-hidden="true"></i>{{ d.status === 'accepted' ? 'Withdraw acceptance' : 'Reject and ask for replacement' }}
              </button>
            </div>
          </article>
        </section>

        <!-- ============ Side panel ============ -->
        <aside class="flex flex-col gap-3">
          <section class="rounded-xl wm-surface p-4" aria-labelledby="provider-heading">
            <h2 id="provider-heading" class="text-[14px] font-semibold text-ink">Provider</h2>
            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-[13px]">
              <dt class="text-[#64748B]">Name</dt><dd class="text-ink">{{ app.providerName }}</dd>
              <dt class="text-[#64748B]">Provider ID</dt><dd class="text-ink">{{ app.providerId }}</dd>
              <dt class="text-[#64748B]">Profession</dt><dd class="text-ink">{{ categoryLabel(app.profession) }}</dd>
              <template v-if="app.services"><dt class="text-[#64748B]">Services</dt><dd class="text-ink">{{ app.services.map(categoryLabel).join(', ') }}</dd></template>
              <dt class="text-[#64748B]">Registered area</dt><dd class="text-ink">{{ app.area }}</dd>
              <template v-if="app.phoneMasked"><dt class="text-[#64748B]">Phone</dt><dd class="text-ink">{{ app.phoneMasked }}</dd></template>
              <template v-for="(v, k) in app.answers" :key="k">
                <dt class="text-[#64748B]">{{ CONDITIONS[k]?.label || k }}</dt><dd class="text-ink">{{ v ? 'Yes' : 'No' }}</dd>
              </template>
              <dt class="text-[#64748B]">Reviewer</dt><dd class="text-ink">{{ app.reviewer || 'Unassigned' }}</dd>
            </dl>
          </section>

          <section class="rounded-xl wm-surface p-4" aria-labelledby="decision-heading">
            <h2 id="decision-heading" class="text-[14px] font-semibold text-ink">Decision</h2>
            <template v-if="canReview">
              <div v-if="isOpen" class="mt-3 flex flex-col gap-2">
                <button v-if="app.reviewer !== me" type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="assign">
                  <i class="bi bi-person-check" aria-hidden="true"></i>Assign to me
                </button>
                <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" :disabled="!approvable.ok" :aria-describedby="`${uid}-approve`" @click="approve">
                  <i class="bi bi-shield-fill-check" aria-hidden="true"></i>Approve application
                </button>
                <ul :id="`${uid}-approve`" class="flex flex-col gap-1 text-[12.5px]">
                  <li v-for="c in approvable.checks" :key="c.label" class="flex items-start gap-1.5" :class="c.ok ? 'text-[#15803D]' : 'text-[#64748B]'">
                    <i :class="['bi mt-px', c.ok ? 'bi-check-circle-fill' : 'bi-circle']" aria-hidden="true"></i>{{ c.label }}
                  </li>
                </ul>
                <button v-if="app.status !== 'info_requested'" type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="requestInfo">
                  <i class="bi bi-chat-left-text" aria-hidden="true"></i>Request more information
                </button>
                <button type="button" class="wm-btn !min-h-10 !rounded-lg border-[#FCA5A5] bg-white text-[#B91C1C] hover:bg-[#FEF2F2]" @click="rejectApp">
                  <i class="bi bi-x-circle" aria-hidden="true"></i>Reject application
                </button>
              </div>
              <div v-else class="mt-2 flex flex-col gap-2">
                <p class="text-[13px] text-[#475569]">{{ decidedText }}</p>
                <button v-if="can('verification.configure')" type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="reopen">
                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reopen application
                </button>
                <p v-else class="text-[12px] text-[#64748B]"><i class="bi bi-lock" aria-hidden="true"></i> Reopening needs the requirement-configuration permission.</p>
              </div>
              <button type="button" class="mt-2 inline-flex items-center gap-1.5 min-h-9 text-[13px] font-medium text-primary hover:underline" @click="addNote">
                <i class="bi bi-sticky" aria-hidden="true"></i>Add reviewer note
              </button>
            </template>
            <p v-else class="mt-2 text-[13px] text-[#64748B]">Your role can view this application but not decide it.</p>
          </section>

          <section class="rounded-xl wm-surface p-4" aria-labelledby="history-heading">
            <h2 id="history-heading" class="text-[14px] font-semibold text-ink">History</h2>
            <ol class="mt-2 flex flex-col gap-2.5">
              <li v-for="(h, i) in [...app.history].reverse()" :key="i" class="flex gap-2.5 text-[13px]">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="h.session ? 'bg-[#F59E0B]' : 'bg-[#CBD5E1]'" aria-hidden="true"></span>
                <span class="min-w-0">
                  <span class="font-medium text-ink">{{ h.label }}</span>
                  <span v-if="h.reason" class="text-[#475569]"> — {{ h.reason }}</span>
                  <span v-if="h.detail" class="block text-[#475569]">{{ h.detail }}</span>
                  <span class="block text-[12px] text-[#64748B]">{{ formatDateTime(h.at) }} · {{ h.actor }}<template v-if="h.session"> · <span class="text-[#92400E]">this browser session</span></template></span>
                </span>
              </li>
            </ol>
          </section>
        </aside>
      </div>
    </template>

    <ActionDialog
      :open="!!dialog"
      :title="dialog?.title || ''"
      :message="dialog?.message || ''"
      :confirm-label="dialog?.confirmLabel || 'Confirm'"
      :tone="dialog?.tone || 'primary'"
      :fields="dialog?.fields || []"
      @close="dialog = null"
      @confirm="v => { dialog.run(v); dialog = null; }"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, useId } from 'vue';
import { useRoute } from 'vue-router';
import { auth } from '@/stores/auth.js';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import AdminStatusBadge from '../components/AdminStatusBadge.vue';
import StateBlock from '../components/StateBlock.vue';
import ActionDialog from '../components/ActionDialog.vue';
import { getVerificationApplication } from '../data/adminApi.js';
import { can } from '../permissions.js';
import { recordChange, recordAudit, actorName, toast } from '../stores/prototypeChanges.js';
import { verification as sharedVerification } from '@/stores/verification.js';
import { CONDITIONS } from '@/data/verificationRequirements.js';
import { categoryLabel } from '@/utils/ui.js';
import { formatDateTime } from '../utils/format.js';

const route = useRoute();
const uid = useId();
// Plain deep copy (structuredClone can't copy Vue's reactive proxies)
const copy = (x) => JSON.parse(JSON.stringify(x));
const me = auth.user?.name || 'Administrator';

const app = ref(null);
const snapshot = ref(null);
const current = ref(null);
const loading = ref(true);
const error = ref('');

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const res = await getVerificationApplication(route.params.id);
    app.value = res.application ? copy(res.application) : null;
    snapshot.value = res.snapshot;
    current.value = res.current;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

const isSession = computed(() => app.value?.source === 'provider_session');
const isOpen = computed(() => ['pending_review', 'under_review', 'info_requested'].includes(app.value?.status));
const canReview = computed(() => can('documents.review'));
const canReviewDocs = computed(() => canReview.value && isOpen.value);
const requiredDocs = computed(() => app.value.documents.filter(d => d.requirement === 'required'));
const acceptedRequired = computed(() => requiredDocs.value.filter(d => docStatus(d) === 'accepted').length);

const formatDate = (iso) => new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
const now = Date.now();
function docStatus(d) {
  // An accepted document past its expiry date is expired, whatever was decided earlier
  if (d.status === 'accepted' && d.expiresAt && new Date(d.expiresAt).getTime() < now) return 'expired';
  return d.status;
}
function expiryNote(d) {
  if (!d.expiresAt) return '';
  const days = Math.round((new Date(d.expiresAt).getTime() - now) / 86400000);
  if (days < 0) return 'expired';
  if (days <= 30) return `expires in ${days} days`;
  return '';
}
const expiryClass = (d) => (expiryNote(d) === 'expired' ? 'text-[#B91C1C]' : expiryNote(d) ? 'text-[#B45309]' : 'text-ink');

// Approval needs a completed review, never just a complete upload
const approvable = computed(() => {
  const reviewed = app.value.documents.filter(d => d.files.length).every(d => d.status !== 'awaiting_review');
  const checks = [
    { label: 'A reviewer is assigned', ok: !!app.value.reviewer },
    { label: `Every required document accepted (${acceptedRequired.value}/${requiredDocs.value.length})`, ok: acceptedRequired.value === requiredDocs.value.length },
    { label: 'Every submitted document reviewed', ok: reviewed },
    { label: 'No required document expired', ok: !requiredDocs.value.some(d => docStatus(d) === 'expired') },
  ];
  return { ok: checks.every(c => c.ok), checks };
});

const decidedText = computed(() => ({
  approved: 'Approved: the provider holds the WorkMate Verified badge.',
  rejected: `Rejected${app.value.decisionReason ? `: ${app.value.decisionReason}` : '.'}`,
  expired: 'Expired: a required document passed its expiry date.',
})[app.value.status] || '');

/* ---------------- Changes ----------------
 * Demo applications: recorded as prototype changes (shown here and in the audit log).
 * Applications submitted in this session: written to the shared store too, so the
 * provider's "Your verification" page shows the decision. Nothing reaches a server.
 */
function commit(patch, label, reason, detail) {
  const at = new Date().toISOString();
  const next = { ...patch, lastActionAt: at };
  if (isSession.value) {
    const sub = sharedVerification.submissions.find(s => s.id === app.value.id);
    Object.assign(sub, copy(next));
    sub.history.push({ at, actor: actorName(), label, reason: reason || null, detail: detail || null, session: true });
    recordAudit(`verification.${label.toLowerCase().replace(/\W+/g, '_')}`, `${label}: ${app.value.id} (${app.value.providerName})`, app.value.id, reason);
    app.value = copy(sub);
  } else {
    recordChange('verification-applications', app.value, { patch: next, label, reason, detail });
    const history = [...app.value.history, { at, actor: actorName(), label, reason: reason || null, detail: detail || null, session: true }];
    app.value = { ...app.value, ...copy(next), history };
  }
  toast(`${label}. Not saved: document verification isn’t connected yet.`);
}

const withDoc = (reqId, change) => app.value.documents.map(d => (d.reqId === reqId ? { ...d, ...change, reviewedBy: me, reviewedAt: new Date().toISOString() } : d));

function acceptDoc(d) {
  commit({ documents: withDoc(d.reqId, { status: 'accepted', note: null }), status: app.value.status === 'pending_review' ? 'under_review' : app.value.status, reviewer: app.value.reviewer || me },
    `Document accepted: ${d.name}`);
}

function rejectDoc(d) {
  dialog.value = {
    title: `Reject “${d.name}”?`, message: 'The provider is asked to upload a replacement. Explain exactly what is wrong.',
    confirmLabel: 'Reject document', tone: 'danger',
    fields: [{ key: 'reason', label: 'What’s wrong with it?', type: 'textarea', required: true, minLength: 5 }],
    run: ({ reason }) => commit({ documents: withDoc(d.reqId, { status: 'replacement_required', note: reason }), status: app.value.status === 'pending_review' ? 'under_review' : app.value.status, reviewer: app.value.reviewer || me },
      `Document rejected: ${d.name}`, reason),
  };
}

const dialog = ref(null);

function assign() {
  commit({ reviewer: me, status: app.value.status === 'pending_review' ? 'under_review' : app.value.status }, `Assigned to ${me}`);
}

function approve() {
  dialog.value = {
    title: `Approve ${app.value.providerName}?`,
    message: 'They get the WorkMate Verified badge for their profession. It confirms these documents were checked, not every aspect of their work.',
    confirmLabel: 'Approve', fields: [{ key: 'reason', label: 'Note for the record (optional)', type: 'text' }],
    run: ({ reason }) => commit({ status: 'approved', decisionReason: null }, 'Application approved: WorkMate Verified granted', reason),
  };
}

function requestInfo() {
  dialog.value = {
    title: 'Request more information', message: 'The provider will be notified through the notification system once it is connected; for now the request is recorded.',
    confirmLabel: 'Send request',
    fields: [{ key: 'reason', label: 'What is missing or needed?', type: 'textarea', required: true, minLength: 5 }],
    run: ({ reason }) => commit({ status: 'info_requested', reviewer: app.value.reviewer || me }, 'More information requested', reason),
  };
}

function rejectApp() {
  dialog.value = {
    title: 'Reject this application?', message: 'The provider is told the reason and can apply again.', confirmLabel: 'Reject application', tone: 'danger',
    fields: [{ key: 'reason', label: 'Reason (shared with the provider)', type: 'textarea', required: true, minLength: 5 }],
    run: ({ reason }) => commit({ status: 'rejected', decisionReason: reason }, 'Application rejected', reason),
  };
}

function reopen() {
  dialog.value = {
    title: 'Reopen this application?', message: 'It returns to review. Any badge stays as it is until a new decision is made.', confirmLabel: 'Reopen',
    fields: [{ key: 'reason', label: 'Why is it being reopened?', type: 'textarea', required: true, minLength: 5 }],
    run: ({ reason }) => commit({ status: 'under_review' }, 'Application reopened', reason),
  };
}

function addNote() {
  dialog.value = {
    title: 'Add reviewer note', message: 'Visible to reviewers only.', confirmLabel: 'Add note',
    fields: [{ key: 'reason', label: 'Note', type: 'textarea', required: true, minLength: 3 }],
    run: ({ reason }) => commit({}, 'Reviewer note', reason),
  };
}

/* ---------------- Secure preview ---------------- */
const previewOpen = ref(null);
function togglePreview(d) {
  if (previewOpen.value === d.reqId) { previewOpen.value = null; return; }
  previewOpen.value = d.reqId;
  // Every opening of a private document is logged
  recordAudit('document.viewed', `Opened ${d.name} for ${app.value.id}`, `${app.value.id}:${d.reqId}`, 'Verification review');
}
</script>
