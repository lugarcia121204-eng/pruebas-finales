<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import { ref } from 'vue';
const props = defineProps({ institutions: Object, states: Array, filters: Object });
const search = ref(props.filters?.search || '');
const state = ref(props.filters?.state || '');
</script>
<template><DynamicLayout><Head title="Control de solicitudes" /><div class="space-y-5"><h1 class="text-2xl font-bold">Control de solicitudes</h1><form @submit.prevent="router.get(route('applications.control.index'),{search,state},{preserveState:true})" class="flex flex-wrap gap-2"><input v-model="search" aria-label="Buscar sede" placeholder="Buscar sede" class="rounded border p-2" /><select v-model="state" aria-label="Estado" class="rounded border p-2"><option value="">Todos los estados</option><option v-for="item in states" :key="item.id" :value="item.name">{{ item.name }}</option></select><button class="rounded bg-blue-900 px-4 text-white">Filtrar</button></form>
<div class="overflow-x-auto rounded-lg border bg-white"><table class="w-full text-left text-sm"><thead class="bg-gray-50"><tr><th class="p-3">Sede</th><th class="p-3">Aprobadas</th><th class="p-3">Rechazadas</th><th class="p-3">Acciones</th></tr></thead><tbody><tr v-for="item in institutions.data" :key="item.id" class="border-t"><td class="p-3">{{ item.name }}</td><td class="p-3">{{ item.approved_count }}</td><td class="p-3">{{ item.rejected_count }}</td><td class="p-3"><Link :href="route('applications.control.show',item.id)" class="text-blue-800 underline">Ver solicitudes</Link></td></tr><tr v-if="!institutions.data.length"><td colspan="4" class="p-6 text-center text-gray-500">No hay solicitudes dictaminadas.</td></tr></tbody></table></div>
<nav class="flex gap-2" aria-label="Paginacion"><template v-for="(link,index) in institutions.meta?.links || institutions.links" :key="index"><Link v-if="link.url" :href="link.url" class="rounded border px-3 py-1" :class="{'bg-blue-100':link.active}" v-html="link.label" /></template></nav></div></DynamicLayout></template>
