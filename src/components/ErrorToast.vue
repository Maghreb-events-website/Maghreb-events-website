<script setup>
import { watch, ref, onBeforeUnmount } from 'vue'
import { useAuth } from '@/composables/useAuth'

const { error } = useAuth()

const visible = ref(false)
let dismissTimer = null
let clearTimer = null

const DISPLAY_MS = 5000
const FADE_MS = 300

watch(error, (message) => {
  clearTimeout(dismissTimer)
  clearTimeout(clearTimer)

  if (!message) {
    visible.value = false
    return
  }

  visible.value = true
  dismissTimer = setTimeout(() => {
    visible.value = false
    // Wait for the fade-out transition to finish before clearing the
    // underlying error, so it doesn't flash to a new message mid-fade.
    clearTimer = setTimeout(() => {
      error.value = null
    }, FADE_MS)
  }, DISPLAY_MS)
})

onBeforeUnmount(() => {
  clearTimeout(dismissTimer)
  clearTimeout(clearTimer)
})
</script>

<template>
  <Transition name="toast-fade">
    <div
      v-if="visible && error"
      class="fixed top-4 right-4 z-50 max-w-sm rounded-lg bg-red-900/95 px-4 py-3 text-sm text-red-100 shadow-lg ring-1 ring-red-700"
      role="alert"
    >
      {{ error }}
    </div>
  </Transition>
</template>

<style scoped>
.toast-fade-enter-active,
.toast-fade-leave-active {
  transition: opacity 0.3s ease, transform 0.3s ease;
}
.toast-fade-enter-from,
.toast-fade-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
