<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader :title="item.label" :description="item.summary" />

    <p class="wm-notice wm-notice-info">
      <i class="bi bi-shield-check" aria-hidden="true"></i>
      <span>These roles are enforced by the server on every request. Give people the role with the least access their job needs; changes are made from Admin Users and recorded in the audit log.</span>
    </p>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[300px_minmax(0,1fr)]">
      <!-- Role list -->
      <section class="rounded-xl wm-surface p-2" aria-labelledby="roles-heading">
        <h2 id="roles-heading" class="px-2 pt-2 pb-1 text-[13px] font-semibold text-[#475569]">Roles</h2>
        <ul>
          <li v-for="r in ROLES" :key="r.id">
            <button
              type="button"
              class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left"
              :class="selectedId === r.id ? 'bg-[#EFF6FF]' : 'hover:bg-[#F8FAFC]'"
              :aria-pressed="selectedId === r.id"
              @click="selectedId = r.id"
            >
              <span class="min-w-0 flex-1">
                <span class="block text-[13.5px] font-medium" :class="selectedId === r.id ? 'text-primary' : 'text-ink'">{{ r.label }}</span>
                <span class="block text-[12px] text-[#64748B]">{{ r.permissions.length }} of {{ total }} permissions</span>
              </span>
              <span v-if="r.id === currentRoleId" class="rounded bg-[#F1F5F9] px-1.5 text-[11px] text-[#475569]">Your role</span>
            </button>
          </li>
        </ul>
      </section>

      <div class="flex min-w-0 flex-col gap-4">
        <section class="rounded-xl wm-surface p-4" aria-labelledby="role-heading">
          <h2 id="role-heading" class="text-[16px] font-semibold text-ink">{{ role.label }}</h2>
          <p class="mt-0.5 text-[13px] text-[#64748B]">{{ role.description }}</p>

          <div class="mt-4 overflow-x-auto rounded-lg border border-[#E2E8F0]">
            <table class="w-full text-[13px]">
              <caption class="sr-only">What {{ role.label }} can do with each kind of record</caption>
              <thead class="bg-[#F8FAFC] text-[#475569]">
                <tr>
                  <th scope="col" class="px-3 py-2 text-left font-semibold">Record</th>
                  <th v-for="v in VERBS" :key="v" scope="col" class="px-2 py-2 text-center font-semibold capitalize">{{ v }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="[name, verbs] in RECORD_ACCESS" :key="name" class="border-t border-[#F1F5F9]">
                  <th scope="row" class="px-3 py-2 text-left font-normal text-ink whitespace-nowrap">{{ name }}</th>
                  <td v-for="v in VERBS" :key="v" class="px-2 py-2 text-center">
                    <template v-if="!verbs[v]"><span class="text-[#CBD5E1]" aria-hidden="true">·</span><span class="sr-only">Not applicable</span></template>
                    <template v-else-if="role.permissions.includes(verbs[v])"><i class="bi bi-check-lg text-[16px] text-[#15803D]" aria-hidden="true"></i><span class="sr-only">Allowed</span></template>
                    <template v-else><i class="bi bi-x text-[16px] text-[#94A3B8]" aria-hidden="true"></i><span class="sr-only">Not allowed</span></template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="mt-2 text-[12px] text-[#64748B]">“·” means the action doesn’t exist for that record. Payments are never deleted: they are reversed or refunded and the history is kept.</p>
        </section>

        <section class="rounded-xl wm-surface p-4" aria-labelledby="perm-heading">
          <h2 id="perm-heading" class="text-[15px] font-semibold text-ink">Permissions</h2>
          <ul class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 md:grid-cols-2">
            <li v-for="(text, key) in PERMISSIONS" :key="key" class="flex items-start gap-2 text-[13px]">
              <i :class="['bi mt-0.5', role.permissions.includes(key) ? 'bi-check-circle-fill text-[#15803D]' : 'bi-circle text-[#CBD5E1]']" aria-hidden="true"></i>
              <span :class="role.permissions.includes(key) ? 'text-ink' : 'text-[#94A3B8]'">
                {{ text }}<span class="sr-only">{{ role.permissions.includes(key) ? ' (granted)' : ' (not granted)' }}</span>
                <code class="ml-1 text-[11px] text-[#94A3B8]">{{ key }}</code>
              </span>
            </li>
          </ul>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import AdminPageHeader from './AdminPageHeader.vue';
import { ROLES, PERMISSIONS, RECORD_ACCESS, currentRoleId } from '../permissions.js';

defineProps({
  def:  { type: Object, default: null },
  item: { type: Object, required: true },
});

const VERBS = ['view', 'create', 'edit', 'approve', 'export', 'delete'];
const total = Object.keys(PERMISSIONS).length;
const selectedId = ref(currentRoleId.value || ROLES[0].id);
const role = computed(() => ROLES.find(r => r.id === selectedId.value));
</script>
