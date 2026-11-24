<template>
  <BottomSheet :open="open" :title="titles[step]" @close="close">
    <!-- 1. Amount -->
    <form v-if="step === 'amount'" :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="toMethod">
      <dl class="grid grid-cols-2 gap-2">
        <div class="rounded-xl bg-primary-tint px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">You owe</dt><dd class="text-[18px] font-bold text-ink">{{ ugx(pay.payable_ugx) }}</dd></div>
        <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Can pay now</dt><dd class="text-[18px] font-bold text-ink">{{ ugx(pay.available_ugx) }}</dd></div>
      </dl>
      <p v-if="pay.reserved_ugx" class="text-[13px] text-muted">{{ ugx(pay.reserved_ugx) }} is already on its way.</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="wm-btn wm-btn-tint !min-h-11" @click="amount = String(pay.available_ugx)">Pay all {{ ugx(pay.available_ugx) }}</button>
      </div>
      <FormField v-model="amount" type="number" inputmode="numeric" min="0" step="100" label="Amount to pay" required prefix="UGX" :error-message="errors.amount" />
      <p v-if="!errors.amount && Number(amount) > 0" class="text-[13px] text-muted">Left to pay after this: {{ ugx(left) }}</p>
    </form>

    <!-- 2. Method -->
    <div v-else-if="step === 'method'" class="flex flex-col gap-3">
      <p class="text-[14px] text-ink">Paying <strong>{{ ugx(Number(amount)) }}</strong></p>
      <ul class="flex flex-col gap-2" role="list">
        <li v-for="m in choices" :key="m.id">
          <button type="button" class="flex min-h-[64px] w-full items-center gap-3 rounded-2xl border px-3.5 py-3 text-left" :class="[m.usable ? 'border-line bg-white' : 'border-line bg-[#F8FAFC] opacity-80', picked === m.id ? 'ring-2 ring-primary' : '']" :aria-pressed="picked === m.id" @click="choose(m)">
            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-[12px] font-extrabold" :style="{ background: m.bg, color: m.fg }"><template v-if="m.badge">{{ m.badge }}</template><i v-else class="bi bi-bank text-lg" aria-hidden="true"></i></span>
            <span class="text-[15px] font-semibold text-ink">{{ m.name }}</span>
          </button>
        </li>
      </ul>
      <p v-if="unavailable" class="text-[14px] font-medium text-ink" role="status">{{ UNAVAILABLE }}</p>
    </div>

    <!-- 3a. Mobile money number -->
    <form v-else-if="step === 'phone'" :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="send">
      <p class="text-[14px] text-ink">{{ methodName(picked) }} · <strong>{{ ugx(Number(amount)) }}</strong></p>
      <FormField v-model="phone" type="tel" inputmode="tel" label="Mobile money number" required placeholder="0772 123 456" autocomplete="tel" :error-message="errors.phone" />
      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <!-- 3b. Manual payment with proof -->
    <form v-else-if="step === 'manual'" :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submitManual">
      <div class="rounded-xl border border-line bg-white/70 px-3.5 py-3 text-[13.5px]">
        <p class="font-medium text-ink">Pay {{ ugx(Number(amount)) }} to WorkMate</p>
        <p class="mt-0.5 whitespace-pre-line text-muted">{{ pay.instructions }}</p>
      </div>
      <FormField v-model="manual.reference" label="Transaction reference" required maxlength="60" :error-message="errors.reference" />
      <FormField v-model="manual.paid_on" type="date" label="Date paid" required :max="today" :error-message="errors.paid_on" />
      <div class="flex flex-col gap-1">
        <label class="wm-btn wm-btn-tint !min-h-11 w-fit cursor-pointer"><i class="bi bi-paperclip" aria-hidden="true"></i>{{ file ? 'Change proof' : 'Add proof of payment' }}
          <input type="file" class="sr-only" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" @change="pick" /></label>
        <p v-if="file" class="text-[13px] text-muted">{{ file.name }} · {{ fileSize(file.size) }}</p>
        <p v-if="errors.proof || fileError" class="wm-field-error" role="alert">{{ fileError || errors.proof }}</p>
      </div>
      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <!-- 4. Waiting for the phone, and the result -->
    <div v-else-if="step === 'waiting'" class="flex flex-col items-center gap-3 py-4 text-center" role="status">
      <span class="wm-spinner" aria-hidden="true"></span>
      <p class="text-[17px] font-semibold text-ink">Payment request sent</p>
      <p class="text-[14px] text-muted">Approve it on your phone ({{ attempt && attempt.phone }}).</p>
      <div v-if="pay.simulator" class="flex gap-2 pt-1">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="simulate('successful')">Approve (test)</button>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="simulate('failed')">Decline (test)</button>
      </div>
    </div>

    <div v-else class="flex flex-col items-center gap-3 py-4 text-center" role="status">
      <span class="grid h-14 w-14 place-items-center rounded-full text-2xl" :class="result.ok ? 'bg-[#DCFCE7] text-[#166534]' : result.waiting ? 'bg-[#FEF3C7] text-[#92400E]' : 'bg-[#FEE2E2] text-[#991B1B]'"><i :class="['bi', result.ok ? 'bi-check-lg' : result.waiting ? 'bi-hourglass-split' : 'bi-x-lg']" aria-hidden="true"></i></span>
      <p class="text-[18px] font-semibold text-ink">{{ result.title }}</p>
      <p v-if="result.left !== null" class="text-[14px] text-muted">Left to pay: <strong class="text-ink">{{ ugx(result.left) }}</strong></p>
      <a v-if="result.receiptUrl" :href="result.receiptUrl" class="wm-btn wm-btn-secondary !min-h-11" download><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>Receipt</a>
    </div>

    <template #footer>
      <template v-if="step === 'amount'">
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" @click="close">Cancel</button>
        <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]">Continue</button>
      </template>
      <template v-else-if="step === 'method'">
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" @click="step = 'amount'">Back</button>
        <button type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="!picked || unavailable" @click="next">Continue</button>
      </template>
      <template v-else-if="step === 'phone' || step === 'manual'">
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="step = 'method'">Back</button>
        <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="busy"><template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>Sending…</template><template v-else>{{ step === 'phone' ? 'Send payment request' : 'Submit for verification' }}</template></button>
      </template>
      <template v-else-if="step === 'waiting'">
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="cancelAttempt">Cancel payment</button>
      </template>
      <template v-else>
        <button type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-1" @click="finish">Done</button>
      </template>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, reactive, computed, watch, onBeforeUnmount, useId } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import FormField from '@/components/FormField.vue';
import { api, upload } from '@/api/client.js';
import { newClientToken } from '@/utils/photos.js';
import { ugx } from '@/utils/pricing.js';
import { fileProblem, fileSize } from '@/utils/disputes.js';
import { UNAVAILABLE, methodName, methodChoices, amountProblem, phoneProblem, leftAfter, manualProblems, hasErrors, isFinal } from '@/utils/payCommission.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  pay: { type: Object, required: true },
});
const emit = defineEmits(['close', 'done']);

const titles = { amount: 'Pay commission', method: 'Choose how to pay', phone: 'Mobile money', manual: 'Manual payment', waiting: 'Mobile money', result: 'Payment' };
const formId = useId();
const step = ref('amount');
const amount = ref('');
const picked = ref('');
const unavailable = ref(false);
const phone = ref('');
const manual = reactive({ reference: '', paid_on: '' });
const file = ref(null);
const fileError = ref('');
const errors = reactive({ amount: '', phone: '', reference: '', paid_on: '', proof: '' });
const error = ref('');
const busy = ref(false);
const attempt = ref(null);
const result = reactive({ ok: false, waiting: false, title: '', left: null, receiptUrl: '' });
const today = new Date().toISOString().slice(0, 10);
let token = newClientToken();
let timer = null;

const choices = computed(() => methodChoices(props.pay.methods));
const left = computed(() => leftAfter(props.pay.payable_ugx, amount.value));

watch(() => props.open, (isOpen) => {
  if (!isOpen) return;
  step.value = 'amount';
  amount.value = String(props.pay.available_ugx || '');
  picked.value = '';
  unavailable.value = false;
  phone.value = '';
  Object.assign(manual, { reference: '', paid_on: '' });
  Object.assign(errors, { amount: '', phone: '', reference: '', paid_on: '', proof: '' });
  file.value = null;
  fileError.value = '';
  error.value = '';
  attempt.value = null;
  token = newClientToken();
});

// A message about a field goes as soon as the field changes
watch(amount, () => { errors.amount = ''; });
watch(phone, () => { errors.phone = ''; });
watch(() => manual.reference, () => { errors.reference = ''; });
watch(() => manual.paid_on, () => { errors.paid_on = ''; });

function toMethod() {
  errors.amount = amountProblem(amount.value, props.pay.available_ugx);
  if (!errors.amount) step.value = 'method';
}

// An unavailable method says one short thing and starts nothing
function choose(m) {
  picked.value = m.id;
  unavailable.value = !m.usable;
}

function next() {
  if (unavailable.value || !picked.value) return;
  error.value = '';
  step.value = picked.value === 'manual' ? 'manual' : 'phone';
}

function pick(event) {
  const f = event.target.files && event.target.files[0];
  event.target.value = '';
  fileError.value = '';
  if (!f) return;
  const problem = fileProblem(f);
  if (problem) fileError.value = problem;
  else file.value = f;
}

function show(title, ok, waiting, leftNow, receiptUrl = '') {
  Object.assign(result, { ok, waiting, title, left: leftNow, receiptUrl });
  step.value = 'result';
}

async function send() {
  if (busy.value) return;
  errors.phone = phoneProblem(phone.value);
  if (errors.phone) return;
  busy.value = true;
  error.value = '';
  try {
    attempt.value = (await api('/me/finance/pay/mobile-money', { method: 'POST', body: { method: picked.value, amount: Number(amount.value), phone: phone.value, client_token: token } })).data;
    step.value = 'waiting';
    poll();
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

function poll() {
  clearTimeout(timer);
  timer = setTimeout(async () => {
    if (step.value !== 'waiting' || !attempt.value) return;
    try {
      attempt.value = (await api(`/me/finance/pay/attempts/${attempt.value.id}`)).data;
    } catch { /* try again */ }
    if (attempt.value && isFinal(attempt.value.status)) await settle();
    else poll();
  }, 3000);
}

async function settle() {
  const a = attempt.value;
  if (a.status === 'successful') {
    emit('done');
    const fresh = (await api('/me/finance/pay')).data;
    show('Payment successful', true, false, fresh.payable_ugx, a.payment_id ? `/api/v1/me/finance/receipts/${a.payment_id}.pdf` : '');
  } else {
    show(a.status === 'failed' ? 'Payment failed' : a.status === 'expired' ? 'Payment expired' : 'Payment cancelled', false, false, null);
  }
}

async function simulate(outcome) {
  try {
    await api(`/me/finance/pay/attempts/${attempt.value.id}/simulate/${outcome}`, { method: 'POST' });
    attempt.value = (await api(`/me/finance/pay/attempts/${attempt.value.id}`)).data;
    await settle();
  } catch (e) {
    error.value = e.message;
  }
}

async function cancelAttempt() {
  busy.value = true;
  try {
    attempt.value = (await api(`/me/finance/pay/attempts/${attempt.value.id}/cancel`, { method: 'POST' })).data;
    await settle();
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

async function submitManual() {
  if (busy.value) return;
  Object.assign(errors, manualProblems(manual, !!file.value, today));
  if (hasErrors(errors)) return;
  busy.value = true;
  error.value = '';
  try {
    const data = new FormData();
    Object.entries({ amount: Number(amount.value), channel: 'manual', reference: manual.reference.trim(), paid_on: manual.paid_on, client_token: token }).forEach(([k, v]) => data.append(k, v));
    data.append('evidence', file.value);
    await upload('/me/commission/payments', data);
    emit('done');
    show('Payment awaiting verification', false, true, null);
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

function close() {
  if (busy.value) return;
  clearTimeout(timer);
  emit('close');
}

function finish() {
  clearTimeout(timer);
  emit('close');
}

onBeforeUnmount(() => clearTimeout(timer));
</script>
