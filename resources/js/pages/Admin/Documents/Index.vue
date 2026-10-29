<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import { ref } from 'vue';
const props = defineProps({ applications: Object, filters: Object });
const search = ref(props.filters?.search || '');
</script>
<template><DynamicLayout><Head title="Expedientes" /><div class="space-y-5"><h1 class="text-2xl font-bold">Expedientes docentes</h1>
<form @submit.prevent="router.get(route('security.documents.index'), {search}, {preserveState:true})" class="flex gap-2"><input v-model="search" aria-label="Buscar expediente" placeholder="Buscar docente o convocatoria" class="rounded border p-2" /><button class="rounded bg-blue-900 px-4 text-white">Buscar</button></form>
<div class="overflow-x-auto rounded-lg border bg-white"><table class="w-full text-left text-sm"><thead class="bg-gray-50"><tr><th class="p-3">Docente</th><th class="p-3">Convocatoria</th><th class="p-3">Documentos</th><th class="p-3">Acciones</th></tr></thead><tbody><tr v-for="item in applications.data" :key="item.id" class="border-t"><td class="p-3">{{ item.user?.name }}</td><td class="p-3">{{ item.announcement?.name }}</td><td class="p-3">{{ item.documents_count }}</td><td class="p-3"><Link :href="route('security.documents.show',item.id)" class="text-blue-800 underline">Ver expediente</Link></td></tr><tr v-if="!applications.data.length"><td colspan="4" class="p-6 text-center text-gray-500">No hay expedientes.</td></tr></tbody></table></div>
<nav class="flex gap-2" aria-label="Paginacion"><template v-for="(link,index) in applications.meta?.links || applications.links" :key="index"><Link v-if="link.url" :href="link.url" class="rounded border px-3 py-1" :class="{'bg-blue-100':link.active}" v-html="link.label" /></template></nav></div></DynamicLayout></template>
