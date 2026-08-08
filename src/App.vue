<script setup>
import { onMounted } from "vue";
import Header from "@/components/Header.vue";
import ErrorToast from "@/components/ErrorToast.vue";
import { useAuth } from "@/composables/useAuth";

const { fetchCurrentUser } = useAuth();

onMounted(() => {
  fetchCurrentUser();
});
</script>

<template>
  <ErrorToast />
  <router-view />
</template>

<style scoped></style>
