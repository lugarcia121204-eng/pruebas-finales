<script setup lang="ts">
import ApplicationReportFilters from '@/components/ApplicationReportFilters.vue';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
const props = defineProps({
    institutions: Object,
    states: Array,
    filters: Object,
    priorityAreas: Array,
    subAreas: Array,
});
const search = ref(props.filters?.search || '');
const state = ref(props.filters?.state || '');
const reportFilters = ref({
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
        if (value) params.append(key, value);
    });
    window.location.href =
        route('applications.control.export') + '?' + params.toString();
};
</script>
<template>
    <DynamicLayout>
        <Head title="Control de solicitudes" />
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold">Control de solicitudes</h1>
                <button
                    @click="exportData"
                    class="rounded bg-green-800 px-4 py-2 text-white"
                >
                    Exportar Excel
                </button>
            </div>
            <form @submit.prevent="applyFilters" class="flex flex-wrap gap-2">
                <input
                    v-model="search"
                    aria-label="Buscar sede"
                    placeholder="Buscar sede"
                    class="rounded border p-2"
                />
                <select
                    v-model="state"
                    aria-label="Estado"
                    class="rounded border p-2"
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
                <button class="rounded bg-blue-900 px-4 text-white">
                    Filtrar
                </button>
                <button
                    type="button"
                    @click="cleanFilters"
                    class="rounded border px-4"
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
            <div class="overflow-x-auto rounded-lg border bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="p-3">Sede</th>
                            <th class="p-3">Aprobadas</th>
                            <th class="p-3">Rechazadas</th>
                            <th class="p-3">Total filtrado</th>
                            <th class="p-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in institutions.data"
                            :key="item.id"
                            class="border-t"
                        >
                            <td class="p-3">{{ item.name }}</td>
                            <td class="p-3">{{ item.approved_count }}</td>
                            <td class="p-3">{{ item.rejected_count }}</td>
                            <td class="p-3">
                                {{ item.filtered_applications_count }}
                            </td>
                            <td class="p-3">
                                <Link
                                    :href="
                                        route('applications.control.show', {
                                            id: item.id,
                                            ...getFilters(),
                                        })
                                    "
                                    class="text-blue-800 underline"
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
            <nav class="flex gap-2" aria-label="Paginación">
                <template
                    v-for="(link, index) in institutions.meta?.links ||
                    institutions.links"
                    :key="index"
                    ><Link
                        v-if="link.url"
                        :href="link.url"
                        class="rounded border px-3 py-1"
                        :class="{ 'bg-blue-100': link.active }"
                        ><span v-html="link.label" /></Link
                    ></template>
            </nav>
        </div>
    </DynamicLayout>
</template>
