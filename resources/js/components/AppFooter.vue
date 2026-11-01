<template>
  <footer class="bg-white border-t border-[#E2E8F0]">
    <div class="wm-container py-10 lg:py-12 grid gap-8 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.4fr)] lg:gap-12">
      <div>
        <AppLogo />
        <p class="mt-2 text-sm text-[#64748B]">Local skills. Real opportunities.</p>
        <p class="mt-3 text-sm text-[#64748B]">A product of <span class="font-medium text-ink">ResNet Systems</span>.</p>
        <button
          v-if="!pwa.standalone"
          type="button"
          class="mt-4 inline-flex items-center gap-2 min-h-11 px-4 rounded-full text-sm font-medium text-primary bg-primary/10 hover:bg-primary/15"
          @click="openInstall"
        >
          <i class="bi bi-phone" aria-hidden="true"></i> Get the app
        </button>
      </div>

      <nav aria-labelledby="footer-quick">
        <h2 id="footer-quick" class="text-sm font-semibold text-ink">Quick Links</h2>
        <ul class="mt-2">
          <li v-for="link in quickLinks" :key="link.to">
            <router-link :to="link.to" class="inline-flex items-center min-h-11 min-w-11 text-sm text-[#64748B] hover:text-primary">{{ link.label }}</router-link>
          </li>
        </ul>
      </nav>

      <nav aria-labelledby="footer-categories">
        <h2 id="footer-categories" class="text-sm font-semibold text-ink">Categories</h2>
        <ul class="mt-2 grid grid-cols-2 gap-x-6">
          <li v-for="c in footerCategories" :key="c.id">
            <router-link
              :to="{ path: '/browse', query: { category: c.id } }"
              class="inline-flex items-center min-h-11 min-w-11 text-sm text-[#64748B] hover:text-primary"
            >{{ c.label }}</router-link>
          </li>
        </ul>
      </nav>
    </div>

    <div class="border-t border-[#E2E8F0]">
      <div class="wm-container py-5 flex flex-wrap items-center justify-between gap-2 text-xs text-[#64748B]">
        <p>© {{ year }} WorkMate by ResNet Systems. All rights reserved. Place names: <a href="https://www.geonames.org/" target="_blank" rel="noopener" class="underline">GeoNames</a> (CC BY 4.0).</p>
        <p class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> Kampala, Uganda</p>
      </div>
    </div>
  </footer>
</template>

<script setup>
import AppLogo from './AppLogo.vue';
import { categories } from '@/stores/catalog.js';
import { pwa, openInstall } from '@/stores/pwa.js';

const year = new Date().getFullYear();

const quickLinks = [
  { to: '/',          label: 'Home' },
  { to: '/browse',    label: 'Browse Services' },
  { to: '/find-work', label: 'Find Work' },
  { to: '/activity',  label: 'My Activity' },
  { to: '/account',   label: 'Account' },
];

const footerCategories = categories.slice(0, 8);
</script>
