<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import { ref } from 'vue';
defineProps({ application: Object });
</script>
<template><DynamicLayout><Head title="Expediente docente" /><div class="space-y-5"><Link :href="route('security.documents.index')" class="text-blue-800 underline">Volver a expedientes</Link><h1 class="text-2xl font-bold">Expediente de {{ application.user?.name }}</h1><p>{{ application.announcement?.name }}</p><ul class="divide-y rounded-lg border bg-white"><li v-for="document in application.documents" :key="document.id" class="flex items-center justify-between gap-4 p-4"><span>{{ document.name }}</span><a :href="route('security.documents.download',document.id)" class="text-blue-800 underline">Descargar</a></li><li v-if="!application.documents?.length" class="p-6 text-gray-500">No hay documentos cargados.</li></ul></div></DynamicLayout></template>
