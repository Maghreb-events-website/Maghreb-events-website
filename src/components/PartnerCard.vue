<script setup>
import { ref } from 'vue'

const numberOfPartners = ref(0)

const partners = ref([
  {
    name: "John Doh Aviation",
    type: "John Doh Aviation",
    status: "verified",
    description: "",
    website: "",
    logo_url: "http://localhost:5173/src/assets/Logo-White.png"
  }
])


numberOfPartners.value = partners.value.length
</script>

<template>
  <div>
<div
  id="partners-container"
  class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"
>
 <component
  v-for="partner in partners"
  :key="partner.name"
  :is="partner.website ? 'a' : 'article'"
  :href="partner.website || undefined"
  target="_blank"
  rel="noopener"
  class="block rounded-xl border p-4 no-underline shadow-sm"
  :style="{
    background: '#374151',
    borderColor: '#374151',
    color: ''
  }"
>
  <div
    class="mb-3 flex h-30 items-center justify-center overflow-hidden rounded-lg"
    :style="{ background: 'var(--me-bg-elev-2)' }"
  >
    <img
      v-if="partner.logo_url"
      :src="partner.logo_url"
      :alt="partner.name"
      class="max-h-4/5 max-w-4/5 object-contain"
    />

    <span
      v-else
      class="material-symbols-outlined text-4xl"
      :style="{ color: '#afb8c4' }"
    >
      corporate_fare
    </span>
  </div>

  <div class="flex items-center justify-between gap-2">
    <h3 class="m-0 text-base font-medium"
    :style="{ color: '#afb8c4' }">
      {{ partner.name }}
    </h3>

    <span
      class="rounded-full px-2 py-1 text-xs uppercase tracking-widest bg-gray-800 transition-all duration-200 hover:-translate-y-0.5     hover:shadow-md"
      :style="{ color: '#afb8c4' }"
    >
      {{ partner.status }}
    </span>
  </div>

  <p
    class="mt-1 text-xs uppercase tracking-widest"
    :style="{ color: '#afb8c4' }"
  >
    {{ partner.type }}
  </p>

  <p
    v-if="partner.description"
    class="mt-2 text-sm"
  >
    {{ partner.description }}
  </p>
</component>
</div>
</div>
</template>