<template>
  <!-- Shown before a request or job is sent: WorkMate never handles job payments -->
  <p class="wm-notice wm-notice-info">
    <i class="bi bi-cash-coin" aria-hidden="true"></i>
    <span>
      WorkMate connects you with service providers. Payment for services is arranged directly between you and the provider.
      WorkMate does not collect or hold your service payment. Confirm the price and payment arrangement with your provider before work begins.
      <router-link to="/legal/disputes" class="font-medium underline">Terms and conditions apply.</router-link>
    </span>
  </p>
</template>
