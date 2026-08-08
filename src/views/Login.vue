<script setup>
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuth } from '@/composables/useAuth'

// This page is never linked to anywhere in the app - it's only ever hit
// because it's the redirect_uri VATSIM sends the browser back to after
// login. It processes the ?code=... and immediately bounces to the
// homepage, so there's nothing for the user to actually see here.

const route = useRoute()
const router = useRouter()
const { completeLogin, error } = useAuth()

onMounted(async () => {
  const { code, state, error: vatsimError } = route.query

  if (vatsimError) {
    error.value = `VATSIM login was cancelled or failed: ${vatsimError}`
    return
  }

  if (code) {
    const success = await completeLogin(String(code), String(state ?? ''))
    if (success) {
      router.replace('/')
    }
    // On failure we stay put briefly so the error below is visible,
    // instead of silently bouncing to the homepage.
  } else {
    router.replace('/')
  }
})
</script>

<template>
  <div class="min-h-[40vh] flex flex-col items-center justify-center gap-3 text-center px-4">
    <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
    <router-link v-if="error" to="/" class="text-sm text-gray-300 underline">Back to home</router-link>
    <p v-else class="text-gray-400 text-sm">Signing in…</p>
  </div>
</template>
