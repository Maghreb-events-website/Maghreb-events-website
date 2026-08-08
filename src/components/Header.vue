<script setup>
import { ref, computed } from "vue";
import { useRoute } from "vue-router";
import { useAuth } from "@/composables/useAuth";

const menuOpen = ref(false);
const route = useRoute();
const { user, loginWithVatsim } = useAuth();

const navLinks = [
  { name: "Home", path: "/" },
  { name: "Partners", path: "/Partners/" },
  { name: "Airlines", path: "/Airlines/" },
  { name: "Planning Team", path: "/Planning/" },
  { name: "Hitsquad", path: "/Hitsquad/" },
  { name: "Pilot Breifing", path: "/Breifing/" },
  { name: "Giveaway", path: "/Giveaway/" },

];

function isActive(path) {
  return path === "/" ? route.path === "/" : route.path.startsWith(path);
}
</script>

<template>
  <header>
    <nav class="relative bg-gray-700">
      <div class="mx-auto max-w-7xl px-2 sm:px-6 lg:px-8">
        <div class="relative flex h-16 items-center justify-between">
          <div class="absolute inset-y-0 left-0 flex items-center sm:hidden">
            <button
              type="button"
              @click="menuOpen = !menuOpen"
              class="relative inline-flex items-center justify-center rounded-md p-2 text-gray-400 hover:bg-white/5 hover:text-white"
            >
              <span class="sr-only">Open main menu</span>
              <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                class="size-6"
              >
                <path
                  d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </button>
          </div>
          <div class="flex flex-1 items-center">
            <div class="hidden sm:block w-full">
              <div class="flex items-center">
                <!-- Logo -->
                <router-link to="/" style="margin-left: -7%; margin-right: 7%">
                  <img
                    src="@/assets/Logo-White.png"
                    alt="Maghreb vACC Logo"
                    width="60"
                    height="60"
                  />
                </router-link>

                <!-- Navigation links -->
                <div class="flex items-center space-x-4">
                  <router-link
                    v-for="link in navLinks"
                    :key="link.path"
                    :to="link.path"
                    class="rounded-md px-3 py-2 text-sm"
                    :class="
                      isActive(link.path)
                        ? 'bg-gray-900 text-white'
                        : 'text-gray-300 hover:bg-white/5 hover:text-white'
                    "
                  >
                    {{ link.name }}
                  </router-link>
                </div>

                <!-- Login / account -->
                <button
                  v-if="!user"
                  @click="loginWithVatsim"
                  class="ml-auto rounded-md text-gray-300 hover:bg-white/5  hover:text-white px-4 py-2 text-sm font-medium"
                >
                  Login
                </button>
                <div v-else class="ml-auto flex items-center gap-3">
                  <span class="text-sm text-gray-300">
                    {{ user.fullName || user.cid }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Mobile menu -->
      <div
        class="overflow-hidden transition-all duration-300 sm:hidden"
        :class="menuOpen ? 'max-h-96' : 'max-h-0'"
      >
        <div class="space-y-1 px-2 pt-2 pb-3">
          <router-link
            v-for="link in navLinks"
            :key="link.path"
            :to="link.path"
            class="block rounded-md px-3 py-2 text-sm font-medium"
            :class="
              isActive(link.path)
                ? 'bg-gray-900 text-white'
                : 'text-gray-300 hover:bg-white/5 hover:text-white'
            "
            @click="menuOpen = false"
          >
            {{ link.name }}
          </router-link>

          <button
            v-if="!user"
            @click="loginWithVatsim(); menuOpen = false"
            class="block w-full text-left rounded-md px-3 py-2 text-sm font-medium text-gray-300 hover:bg-white/5 hover:text-white"
          >
            Login
          </button>
          <span
            v-else
            class="block px-3 py-2 text-sm font-medium text-gray-300"
          >
            {{ user.fullName || user.cid }}
          </span>
        </div>
      </div>
    </nav>
  </header>
</template>

<style scoped>
</style>