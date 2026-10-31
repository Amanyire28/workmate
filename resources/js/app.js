import { createApp } from 'vue';
import router from './router/index.js';
import App from './App.vue';
import { loadSession } from './stores/auth.js';
import { initPwa } from './stores/pwa.js';

// app.css is loaded by the Blade @vite directive; importing it here too
// would bundle the stylesheet twice.

// Find out who is signed in, without holding up the first render
loadSession();

// Installable app: service worker, release updates and the install offer.
// Started before mounting so the browser's install offer isn't missed.
initPwa();

const app = createApp(App);
app.use(router);
// Wait for the first route so the right shell (site or admin) renders from the start
router.isReady().then(() => app.mount('#app'));
