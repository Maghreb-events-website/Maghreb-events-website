<script setup>
import { ref, onMounted, onUnmounted } from "vue";

const UPCOMING_EVENT = {
  title: "New Year's in Morocco",
  start_time: "2026-12-31T22:00:00Z",
};

const days = ref("--");
const hrs = ref("--");
const min = ref("--");
const sec = ref("--");

let timer = null;

function pad(n) {
  return String(n).padStart(2, "0");
}

function tick() {
  const target = new Date(UPCOMING_EVENT.start_time).getTime();
  const diff = Math.max(0, target - Date.now());

  days.value = pad(Math.floor(diff / 86400000));
  hrs.value = pad(Math.floor((diff % 86400000) / 3600000));
  min.value = pad(Math.floor((diff % 3600000) / 60000));
  sec.value = pad(Math.floor((diff % 60000) / 1000));
}

onMounted(() => {
  tick();
  timer = setInterval(tick, 1000);
});

onUnmounted(() => {
  clearInterval(timer);
});
</script>

<template>
  <div
    class="bg-surface-container-low p-10 rounded-xl border-b-2 border-primary/20 flex flex-col items-center w-full max-w-md mx-auto"
  >
    <p
      class="text-on-surface-variant text-[10px] uppercase tracking-widest mb-6"
    >
      {{ UPCOMING_EVENT.title }}
    </p>
    <div class="flex gap-6 justify-center">
      <div class="flex flex-col items-center">
        <span class="text-4xl font-light text-on-surface">{{ days }}</span>
        <span
          class="text-[9px] text-on-surface-variant uppercase tracking-tighter mt-1"
          >Days</span
        >
      </div>
      <span class="text-4xl font-light text-outline-variant self-start mt-1"
        >:</span
      >
      <div class="flex flex-col items-center">
        <span class="text-4xl font-light text-on-surface">{{ hrs }}</span>
        <span
          class="text-[9px] text-on-surface-variant uppercase tracking-tighter mt-1"
          >Hrs</span
        >
      </div>
      <span class="text-4xl font-light text-outline-variant self-start mt-1"
        >:</span
      >
      <div class="flex flex-col items-center">
        <span class="text-4xl font-light text-on-surface">{{ min }}</span>
        <span
          class="text-[9px] text-on-surface-variant uppercase tracking-tighter mt-1"
          >Min</span
        >
      </div>
      <span class="text-4xl font-light text-outline-variant self-start mt-1"
        >:</span
      >
      <div class="flex flex-col items-center">
        <span class="text-4xl font-light text-on-surface">{{ sec }}</span>
        <span
          class="text-[9px] text-on-surface-variant uppercase tracking-tighter mt-1"
          >Sec</span
        >
      </div>
    </div>
  </div>
</template>
