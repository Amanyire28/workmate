<template>
  <!-- Decorative: the search status next to it carries the meaning for screen readers -->
  <div
    ref="root"
    class="wm-radar"
    :class="{ 'is-active': active, 'is-paused': !onScreen }"
    aria-hidden="true"
  >
    <svg viewBox="0 0 200 200" class="absolute inset-0 w-full h-full">
      <circle cx="100" cy="100" r="98" class="wm-radar-edge" />
      <circle cx="100" cy="100" r="66" class="wm-radar-ring" />
      <circle cx="100" cy="100" r="33" class="wm-radar-ring" />
      <line v-for="a in [0, 45, 90, 135]" :key="a" x1="100" y1="6" x2="100" y2="194" class="wm-radar-guide" :transform="`rotate(${a} 100 100)`" />
      <line
        v-for="t in 36" :key="`t${t}`"
        x1="100" y1="2.5" x2="100" :y2="t % 3 === 1 ? 8 : 5.5"
        class="wm-radar-tick"
        :transform="`rotate(${(t - 1) * 10} 100 100)`"
      />
      <text x="100" y="17" class="wm-radar-north">N</text>
    </svg>

    <!-- Current search radius: widens when the search expands -->
    <span class="wm-radar-range" :style="{ transform: `scale(${range})` }"></span>

    <span class="wm-radar-pulse"></span>
    <span class="wm-radar-pulse wm-radar-pulse-late"></span>
    <span class="wm-radar-sweep"></span>

    <span
      v-for="m in markers"
      :key="m.id"
      class="wm-radar-marker"
      :class="[`is-${m.state}`, { 'is-far': m.far }]"
      :style="{ left: `${m.x}%`, top: `${m.y}%` }"
    >
      <span class="wm-radar-dot"></span>
      <span class="wm-radar-badge">{{ m.initials }}</span>
    </span>

    <span class="wm-radar-center"><i class="bi bi-geo-alt-fill"></i></span>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';

defineProps({
  /** Sweep and pulses run only while searching */
  active:  { type: Boolean, default: false },
  /** 0–1: the search radius as a share of the radar */
  range:   { type: Number, default: 0.4 },
  /** [{ id, x, y (% of the box), initials, state: 'scanning' | 'found', far }] */
  markers: { type: Array, default: () => [] },
});

// Pause the animation while scrolled off screen
const root = ref(null);
const onScreen = ref(true);
let observer = null;

onMounted(() => {
  if (!('IntersectionObserver' in window)) return;
  observer = new IntersectionObserver(([entry]) => { onScreen.value = entry.isIntersecting; });
  observer.observe(root.value);
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<style scoped>
.wm-radar {
  position: relative;
  flex-shrink: 0;
  width: 212px;
  aspect-ratio: 1;
  border-radius: 50%;
  overflow: hidden;
  isolation: isolate;
  background:
    radial-gradient(circle at 50% 50%, rgba(23, 105, 210, 0.22), transparent 62%),
    radial-gradient(circle at 50% 50%, #0F2A4F 0%, #0A1C38 58%, #071429 100%);
  box-shadow:
    0 0 0 1px rgba(125, 211, 252, 0.16),
    inset 0 1px 0 rgba(255, 255, 255, 0.10),
    0 18px 40px rgba(2, 12, 27, 0.35);
  transition: box-shadow 0.6s ease;
}
@media (min-width: 640px)  { .wm-radar { width: 236px; } }
@media (min-width: 1024px) { .wm-radar { width: 256px; } }

.wm-radar.is-active {
  box-shadow:
    0 0 0 1px rgba(125, 211, 252, 0.24),
    inset 0 1px 0 rgba(255, 255, 255, 0.12),
    0 0 44px rgba(56, 189, 248, 0.22),
    0 18px 40px rgba(2, 12, 27, 0.35);
}

/* Faint glass highlight across the top */
.wm-radar::after {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  pointer-events: none;
  background: radial-gradient(120% 70% at 30% -10%, rgba(255, 255, 255, 0.14), transparent 50%);
  z-index: 5;
}

.wm-radar-edge  { fill: none; stroke: rgba(125, 211, 252, 0.28); stroke-width: 1; }
.wm-radar-ring  { fill: none; stroke: rgba(125, 211, 252, 0.16); stroke-width: 0.8; }
.wm-radar-guide { stroke: rgba(125, 211, 252, 0.08); stroke-width: 0.6; }
.wm-radar-tick  { stroke: rgba(125, 211, 252, 0.30); stroke-width: 0.8; }
.wm-radar-north { fill: rgba(165, 243, 252, 0.55); font-size: 7px; font-weight: 600; text-anchor: middle; font-family: inherit; }

.wm-radar-range {
  position: absolute;
  inset: 4%;
  border-radius: 50%;
  border: 1px dashed rgba(103, 232, 249, 0.45);
  background: radial-gradient(circle, rgba(34, 211, 238, 0.07), rgba(34, 211, 238, 0.02) 70%, transparent 71%);
  transition: transform 0.9s cubic-bezier(0.2, 0.7, 0.2, 1);
}

/* Rotating sweep: transform-only, so it stays on the compositor */
.wm-radar-sweep {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: conic-gradient(
    from 0deg,
    rgba(23, 105, 210, 0) 0deg,
    rgba(23, 105, 210, 0) 265deg,
    rgba(56, 189, 248, 0.10) 315deg,
    rgba(103, 232, 249, 0.38) 359deg,
    rgba(103, 232, 249, 0) 360deg
  );
  opacity: 0;
  transition: opacity 0.5s ease;
  will-change: transform;
}
.wm-radar-sweep::after {
  content: "";
  position: absolute;
  left: calc(50% - 0.75px);
  top: 2%;
  width: 1.5px;
  height: 48%;
  border-radius: 1px;
  background: linear-gradient(to top, rgba(165, 243, 252, 0.1), rgba(165, 243, 252, 0.9));
  box-shadow: 0 0 8px rgba(103, 232, 249, 0.8);
}
.is-active .wm-radar-sweep {
  opacity: 1;
  animation: wm-radar-spin 3.6s linear infinite;
}

/* Soft rings expanding from the centre */
.wm-radar-pulse {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  border: 1px solid rgba(103, 232, 249, 0.5);
  opacity: 0;
  transform: scale(0.12);
}
.is-active .wm-radar-pulse { animation: wm-radar-pulse 3.6s ease-out infinite; }
.is-active .wm-radar-pulse-late { animation-delay: 1.8s; }

.is-paused .wm-radar-sweep,
.is-paused .wm-radar-pulse,
.is-paused .wm-radar-marker * { animation-play-state: paused; }

/* Centre: the search origin, deliberately unlike the provider markers */
.wm-radar-center {
  position: absolute;
  left: 50%;
  top: 50%;
  z-index: 3;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  margin: -17px 0 0 -17px;
  border-radius: 50%;
  color: #fff;
  font-size: 16px;
  background: linear-gradient(180deg, #2B7BE6, #1769D2);
  box-shadow: 0 0 0 4px rgba(23, 105, 210, 0.30), 0 0 0 9px rgba(23, 105, 210, 0.12), 0 4px 12px rgba(2, 12, 27, 0.5);
}

/* Provider markers: faint dot while scanning → pulse → small initials badge once found */
.wm-radar-marker {
  position: absolute;
  z-index: 2;
  width: 0;
  height: 0;
}
.wm-radar-dot {
  position: absolute;
  left: -3.5px;
  top: -3.5px;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #67E8F9;
  opacity: 0.35;
  transform: scale(0.8);
  transition: opacity 0.4s ease, transform 0.4s ease;
  animation: wm-radar-appear 0.5s ease-out both;
}
.wm-radar-badge {
  position: absolute;
  left: -13px;
  top: -13px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #0B1B33;
  background: linear-gradient(180deg, #E0F7FF, #A5E9FB);
  box-shadow: 0 0 0 2px rgba(11, 27, 51, 0.9), 0 0 14px rgba(103, 232, 249, 0.55);
  opacity: 0;
  transform: scale(0.4);
  transition: opacity 0.35s ease 0.4s, transform 0.35s cubic-bezier(0.2, 0.9, 0.3, 1.3) 0.4s;
}
.is-found .wm-radar-dot {
  opacity: 1;
  transform: scale(1);
  animation: wm-radar-ping 0.9s ease-out 1;
}
.is-found .wm-radar-badge { opacity: 1; transform: scale(1); }
/* Further out (wider search): calmer colour */
.is-far .wm-radar-badge { background: linear-gradient(180deg, #DBEAFE, #93C5FD); box-shadow: 0 0 0 2px rgba(11, 27, 51, 0.9); }
.is-far .wm-radar-dot { background: #93C5FD; }

@keyframes wm-radar-spin   { to { transform: rotate(360deg); } }
@keyframes wm-radar-pulse  {
  0%   { opacity: 0;    transform: scale(0.12); }
  15%  { opacity: 0.55; }
  100% { opacity: 0;    transform: scale(1); }
}
@keyframes wm-radar-appear { from { opacity: 0; transform: scale(0.2); } }
@keyframes wm-radar-ping   {
  0%   { box-shadow: 0 0 0 0 rgba(103, 232, 249, 0.6); }
  100% { box-shadow: 0 0 0 14px rgba(103, 232, 249, 0); }
}

/* Reduced motion: a still radar. animation-name must go too — the global rule only
   shortens durations, which would make an infinite rotation flicker. */
@media (prefers-reduced-motion: reduce) {
  .wm-radar *,
  .wm-radar *::after { animation-name: none !important; transition-duration: 0.01ms !important; }
  .is-active .wm-radar-sweep { transform: rotate(40deg); }
  .wm-radar-pulse { display: none; }
}
</style>
