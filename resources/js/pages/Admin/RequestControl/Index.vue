<script setup lang="ts">
import ApplicationReportFilters from '@/components/ApplicationReportFilters.vue';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { route } from 'ziggy-js';
import type { State, PriorityArea, SubArea, ReportFilters, PaginationLink } from '@/types';
interface InstitutionSummary {
    id: number;
    name: string;
    approved_count: number;
    rejected_count: number;
    filtered_applications_count: number;
}
const props = withDefaults(defineProps<{
    institutions: { data: InstitutionSummary[]; links: PaginationLink[]; meta?: { links?: PaginationLink[] } };
    states?: State[];
    filters?: ReportFilters & { search?: string; state?: string };
    priorityAreas?: PriorityArea[];
    subAreas?: SubArea[];
}>(), {
    states: () => [],
    filters: () => ({}),
    priorityAreas: () => [],
    subAreas: () => [],
});
const search = ref(props.filters?.search || '');
const state = ref(props.filters?.state || '');
const reportFilters = ref<ReportFilters>({
    date_from: props.filters?.date_from,
    date_to: props.filters?.date_to,
    priority_area_id: props.filters?.priority_area_id,
    sub_area_id: props.filters?.sub_area_id,
    status: props.filters?.status,
});
const getFilters = () => ({
    search: search.value,
    state: state.value,
    ...reportFilters.value,
});
const applyFilters = () =>
    router.get(route('applications.control.index'), getFilters(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
const cleanFilters = () => {
    search.value = '';
    state.value = '';
    reportFilters.value = {};
    applyFilters();
};
const exportData = () => {
    const params = new URLSearchParams();
    Object.entries(getFilters()).forEach(([key, value]) => {
        if (value) params.append(key, String(value));
    });
    window.location.href =
        route('applications.control.export') + '?' + params.toString();
};
</script>
<template>
    <DynamicLayout>
        <Head title="Control de solicitudes" />
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl">Control de solicitudes</h1>
                <button
                    @click="exportData"
                    class="cursor-pointer rounded-lg bg-[#0D7239] px-4 py-2.5 font-medium text-white transition hover:bg-green-800"
                >
                    Exportar Excel
                </button>
            </div>
            <form @submit.prevent="applyFilters" class="flex flex-wrap gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-md">
                <input
                    v-model="search"
                    aria-label="Buscar sede"
                    placeholder="Buscar sede"
                    class="h-[45px] w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-700 sm:w-auto"
                />
                <select
                    v-model="state"
                    aria-label="Estado"
                    class="h-[45px] w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-700 sm:w-auto"
                >
                    <option value="">Todos los estados</option>
                    <option
                        v-for="item in states"
                        :key="item.id"
                        :value="item.name"
                    >
                        {{ item.name }}
                    </option>
                </select>
                <button class="cursor-pointer rounded-lg bg-[var(--institutional-primary)] px-4 py-2 text-sm font-medium text-white">
                    Filtrar
                </button>
                <button
                    type="button"
                    @click="cleanFilters"
                    class="cursor-pointer rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50"
                >
                    Limpiar filtros
                </button>
            </form>
            <ApplicationReportFilters
                v-model="reportFilters"
                :priority-areas="priorityAreas"
                :sub-areas="subAreas"
                @change="applyFilters"
            />
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-md">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--institutional-primary)] text-xs font-semibold uppercase text-white">
                        <tr>
                            <th scope="col" class="px-6 py-4 tracking-wider">Sede</th>
                            <th scope="col" class="px-6 py-4 tracking-wider">Aprobadas</th>
                            <th scope="col" class="px-6 py-4 tracking-wider">Rechazadas</th>
                            <th scope="col" class="px-6 py-4 tracking-wider">Total filtrado</th>
                            <th scope="col" class="px-6 py-4 tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <tr
                            v-for="item in institutions.data"
                            :key="item.id"
                            class="transition hover:bg-gray-50"
                        >
                            <td class="px-6 py-4 text-gray-700">{{ item.name }}</td>
                            <td class="px-6 py-4 text-gray-700">{{ item.approved_count }}</td>
                            <td class="px-6 py-4 text-gray-700">{{ item.rejected_count }}</td>
                            <td class="px-6 py-4 text-gray-700">
                                {{ item.filtered_applications_count }}
                            </td>
                            <td class="px-6 py-4 text-gray-700">
                                <Link
                                    :href="
                                        route('applications.control.show', {
                                            id: item.id,
                                            ...getFilters(),
                                        })
                                    "
                                    class="font-medium text-[var(--institutional-primary)] underline-offset-4 hover:underline"
                                    >Ver solicitudes</Link
                                >
                            </td>
                        </tr>
                        <tr v-if="!institutions.data.length">
                            <td
                                colspan="5"
                                class="p-6 text-center text-gray-500"
                            >
                                No hay solicitudes para los filtros
                                seleccionados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <nav class="flex gap-2 overflow-x-auto rounded-lg border border-gray-200 bg-white p-4" aria-label="Paginación">
                <template
                    v-for="(link, index) in institutions.meta?.links ||
                    institutions.links"
                    :key="index"
                    ><Link
                        v-if="link.url"
                        :href="link.url"
                        class="shrink-0 rounded-full border border-gray-200 px-3 py-1 text-sm text-gray-700 transition hover:bg-gray-50"
                        :class="{ 'bg-gray-100 font-semibold': link.active }"
                        ><span v-html="link.label" /></Link
                    ></template>
            </nav>
        </div>
    </DynamicLayout>
</template>
