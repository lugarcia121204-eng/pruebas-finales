<script setup>
import { useStyleStore } from '@/stores/style';
import { Sun, Moon } from 'lucide-vue-next';
const styleStore = useStyleStore();
</script>
<template>
  <div class="flex items-center gap-2">
    <Sun class="h-5 w-5 text-[var(--institutional-primary)] dark:text-slate-400" aria-hidden="true" />
    <button type="button" role="switch" :aria-checked="styleStore.darkMode" :aria-label="styleStore.darkMode ? 'Activar modo claro' : 'Activar modo oscuro'" @click="styleStore.setDarkMode()" class="relative h-7 w-12 rounded-full bg-[var(--institutional-primary)] dark:bg-[#d88a00] transition-colors ring-1 ring-white/30 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-400 cursor-pointer">
      <span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white transition-transform" :class="styleStore.darkMode ? 'translate-x-5' : ''" />
    </button>
    <Moon class="h-5 w-5 text-slate-400 dark:text-[#ffbf00]" aria-hidden="true" />
  </div>
</template>
