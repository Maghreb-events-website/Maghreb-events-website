<script setup>
import Header from "@/components/Header.vue";
import DrawIcon from "@/components/CountDownIcon.vue";
import Footer from "@/components/Footer.vue";
</script>

<template>
  <Header />
  <img
    src="@/assets/Banner.png"
    alt="Maghreb vACC Logo"
    width="75%"
    class="banner-image"
  />
  <DrawIcon />
  <Footer/>
</template>

<style scoped>
.banner-image {
  display: block;
  margin: 24px auto 0;
  border-radius: 12px;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
}

</style>
