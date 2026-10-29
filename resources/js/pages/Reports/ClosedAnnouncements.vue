<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { FileCheck, CheckCircle2, Download } from 'lucide-vue-next';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
const props = defineProps({ announcements: Array, selectedAnnouncementId: Number, localApproved: Object, nationalApproved: Object });
const sections = computed(() => [...(props.localApproved ? [{ title: 'Aprobados en etapa local', list: props.localApproved, key: 'local' }] : []), ...(props.nationalApproved ? [{ title: 'Aprobados en etapa nacional', list: props.nationalApproved, key: 'national' }] : [])]);
const selected = ref(props.selectedAnnouncementId || '');
watch(() => props.selectedAnnouncementId, value => { selected.value = value || ''; });
const filter = () => router.get(route('reports.closed-announcements'), { announcement_id: selected.value || undefined }, { preserveScroll: true });
</script>

<template>
    <DynamicLayout>
        <Head title="Reportes de convocatorias cerradas" />
        <div class="space-y-6">
            <div><h1 class="text-3xl font-bold text-gray-900">Reportes</h1><p class="mt-2 flex items-center gap-2 text-gray-600"><FileCheck class="h-5 w-5" />Listado de docentes aprobados en convocatorias cerradas</p></div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <label for="report-announcement" class="mb-2 block font-semibold text-gray-900">Convocatoria cerrada</label>
                <select id="report-announcement" v-model="selected" @change="filter" :disabled="!announcements.length" class="w-full rounded-lg border border-gray-300 p-3">
                    <option v-if="!announcements.length" value="">No hay convocatorias cerradas disponibles</option>
                    <option v-for="announcement in announcements" :key="announcement.id" :value="announcement.id">{{ announcement.name }}</option>
                </select>
            </div>
            <template v-if="selectedAnnouncementId">
                <section v-for="section in sections" :key="section.key" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-6 py-5"><CheckCircle2 class="h-5 w-5 text-green-600" /><h2 class="text-lg font-bold text-gray-900">{{ section.title }}</h2><span class="rounded-full bg-green-50 px-3 py-1 text-sm font-semibold text-green-700">{{ section.list.total }}</span><a :href="route('reports.closed-announcements.pdf', { announcement: selectedAnnouncementId, stage: section.key })" class="ml-auto inline-flex items-center gap-2 rounded-lg border border-[var(--institutional-primary)] px-4 py-2 text-xs font-bold text-[var(--institutional-primary)] shadow-sm hover:bg-blue-50"><Download class="h-4 w-4" />Descargar PDF</a></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[680px] text-left text-sm">
                        <thead class="bg-[var(--institutional-primary)] text-xs uppercase text-white"><tr><th scope="col" class="px-6 py-4">ID de solicitud</th><th scope="col" class="px-6 py-4">Docente</th><th scope="col" class="px-6 py-4">Institución</th><th scope="col" class="px-6 py-4">Correo electrónico</th></tr></thead>
                        <tbody class="divide-y divide-gray-100"><tr v-for="(teacher, index) in section.list.data" :key="teacher.id" class="hover:bg-gray-50"><td class="px-6 py-4">{{ (section.list.from ?? 1) + index }}</td><td class="px-6 py-4 font-medium text-gray-900">{{ teacher.name }}</td><td class="px-6 py-4 text-gray-600">{{ teacher.institution || 'Sin institución asignada' }}</td><td class="px-6 py-4 text-gray-600">{{ teacher.email }}</td></tr><tr v-if="!section.list.data.length"><td colspan="4" class="px-6 py-10 text-center text-gray-500">No hay docentes aprobados en esta etapa.</td></tr></tbody>
                    </table></div>
                    <div class="border-t border-gray-100 px-6 py-4"><Pagination :links="section.list.links" :total="section.list.total" :from="section.list.from" :to="section.list.to" /></div>
                </section>
            </template>
        </div>
    </DynamicLayout>
</template>
