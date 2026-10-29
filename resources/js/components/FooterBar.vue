<script setup>
import { Link } from '@inertiajs/vue3';
const year = new Date().getFullYear();
</script>
<template>
  <footer class="shrink-0 border-t border-gray-200 bg-white px-4 py-4 text-center text-xs text-gray-600 dark:border-slate-700 dark:bg-[#1a1a2e] dark:text-slate-300 sm:flex sm:items-center sm:justify-center sm:gap-3">
    <p><strong>© {{ year }}, <Link :href="route('dashboard.index')" class="text-[#1b396a] hover:underline dark:text-blue-300">TecNM - CENIDET</Link>.</strong> Todos los derechos reservados.</p>
    <p class="mt-1 sm:mt-0">Sistema EDD</p>
  </footer>
</template>
