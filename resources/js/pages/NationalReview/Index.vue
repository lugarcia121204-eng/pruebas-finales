<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onUnmounted } from 'vue';
import debounce from 'lodash/debounce';
import VueSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { FileText, Filter, ListFilter, Search, Eye, Users, ArrowDownUp } from 'lucide-vue-next';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import Pagination from '@/Shared/Pagination.vue';

const props = defineProps({ reviews: Object, filters: { type: Object, default: () => ({}) } });
const search = ref(props.filters.search ?? '');
const searchField = ref(props.filters.search_field ?? '');
const searchOptions = [
    { label: 'Todos los campos', value: '' },
    { label: 'Docente', value: 'teacher' },
    { label: 'Institución', value: 'institution' },
    { label: 'Convocatoria', value: 'announcement' },
];
const status = ref(props.filters.status ?? '');
const rows = ref(Number(props.filters.rows ?? 10));
const sortField = ref(props.filters.sort_field ?? '');
const sortDirection = ref(props.filters.sort_direction ?? 'asc');
const rowOptions = [5, 10, 25, 50].map(value => ({ label: `${value} Registros`, value }));
const statusOptions = [{ label: 'Todos los estados', value: '' }, { label: 'Pendiente', value: 'pending' }, { label: 'Aprobada', value: 'approved' }, { label: 'Rechazada', value: 'rejected' }];
const applyFilters = () => {
    onSearch.cancel();
    router.get(route('national-review.index'), { search: search.value || undefined, search_field: searchField.value || undefined, status: status.value || undefined, rows: rows.value, sort_field: sortField.value || undefined, sort_direction: sortField.value ? sortDirection.value : undefined }, { preserveState: true, replace: true, preserveScroll: true });
};
const onSearch = debounce(applyFilters, 500);
onUnmounted(() => onSearch.cancel());
const clearFilters = () => { search.value = ''; searchField.value = ''; status.value = ''; rows.value = 10; sortField.value = ''; sortDirection.value = 'asc'; applyFilters(); };
const sortBy = (field) => { sortDirection.value = sortField.value === field && sortDirection.value === 'asc' ? 'desc' : 'asc'; sortField.value = field; applyFilters(); };
const statusLabel = (value) => ({ pending: 'Pendiente', approved: 'Aprobada', rejected: 'Rechazada' }[value] ?? value);
</script>

<template>
    <DynamicLayout>
        <Head title="Solicitudes nacionales" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Solicitudes nacionales</h1>
                <div class="flex items-center gap-2 mt-2 text-sm"><FileText class="w-4 h-4 text-[var(--institutional-primary)]" aria-hidden="true" /><span class="text-gray-900 font-semibold">Solicitudes nacionales</span></div>
            </div>
            <form @submit.prevent="applyFilters" class="bg-white rounded-lg shadow-md border border-gray-200 p-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                    <div class="flex items-center gap-2"><Filter class="h-5 w-5 text-gray-700" aria-hidden="true" /><h2 class="text-xl font-semibold text-gray-800">Filtro de Búsqueda</h2></div>
                    <button type="button" @click="clearFilters" class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm font-medium transition"><ListFilter class="h-4 w-4" aria-hidden="true" />Limpiar Filtros</button>
                </div>
                <p class="text-sm text-gray-500 mb-4">Buscar y filtrar solicitudes asignadas para el segundo filtro de revisión.</p>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:flex">
                    <div class="relative w-full xl:flex-1"><Search class="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-[var(--institutional-primary)] pointer-events-none" aria-hidden="true" /><input v-model="search" @input="onSearch" type="search" aria-label="Buscar solicitudes nacionales" placeholder="Escribe para buscar…" class="pl-10 w-full h-[45px] rounded-lg border border-gray-300 text-gray-700 focus:border-[var(--institutional-primary)] focus:ring-[var(--institutional-primary)] hover:bg-gray-50 transition" /></div>
                    <div class="w-full xl:w-48 shrink-0"><VueSelect input-id="national-search-field" aria-label="Campo de búsqueda" v-model="searchField" :options="searchOptions" :reduce="option => option.value" :searchable="false" :clearable="false" class="vue-select-custom" @update:modelValue="applyFilters" /></div>
                    <div class="w-full xl:w-52 shrink-0"><VueSelect input-id="national-status" aria-label="Estado nacional" v-model="status" :options="statusOptions" :reduce="option => option.value" :searchable="false" :clearable="false" class="vue-select-custom" @update:modelValue="applyFilters" /></div>
                    <div class="w-full xl:w-44 shrink-0"><VueSelect input-id="national-rows" aria-label="Registros por página" v-model="rows" :options="rowOptions" :reduce="option => option.value" :searchable="false" :clearable="false" class="vue-select-custom" @update:modelValue="applyFilters" /></div>
                </div>
            </form>
            <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-[var(--institutional-primary)] text-white uppercase text-xs font-semibold"><tr>
                            <th scope="col" class="px-6 py-4 tracking-wider">ID</th>
                            <th scope="col" class="px-6 py-4 tracking-wider" :aria-sort="sortField === 'teacher' ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"><button type="button" @click="sortBy('teacher')" class="flex items-center gap-1 hover:text-gray-200 uppercase">Docente<ArrowDownUp class="h-4 w-4 opacity-50" aria-hidden="true" /></button></th>
                            <th scope="col" class="px-6 py-4 tracking-wider" :aria-sort="sortField === 'institution' ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"><button type="button" @click="sortBy('institution')" class="flex items-center gap-1 hover:text-gray-200 uppercase">Institución<ArrowDownUp class="h-4 w-4 opacity-50" aria-hidden="true" /></button></th>
                            <th scope="col" class="px-6 py-4 tracking-wider">Convocatoria</th>
                            <th scope="col" class="px-6 py-4 tracking-wider">Evaluador(es)</th>
                            <th scope="col" class="px-6 py-4 tracking-wider" :aria-sort="sortField === 'status' ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"><button type="button" @click="sortBy('status')" class="flex items-center gap-1 hover:text-gray-200 uppercase">Estado nacional<ArrowDownUp class="h-4 w-4 opacity-50" aria-hidden="true" /></button></th>
                            <th scope="col" class="px-6 py-4 tracking-wider text-center">Acciones</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="review in reviews.data" :key="review.id" class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 font-medium text-gray-900">{{ review.application_id }}</td>
                                <td class="px-6 py-4 font-semibold text-gray-900"><span class="block max-w-60 truncate" :title="review.teacher">{{ review.teacher || 'No registrado' }}</span></td>
                                <td class="px-6 py-4 text-gray-600 min-w-56">{{ review.institution || 'Sin institución asignada' }}</td>
                                <td class="px-6 py-4 text-gray-600">{{ review.announcement || 'No registrada' }}</td>
                                <td class="px-6 py-4 text-gray-600 whitespace-nowrap"><span class="inline-flex items-center gap-1.5"><Users class="h-4 w-4 text-[var(--institutional-primary)]" aria-hidden="true" />{{ review.evaluators_count }} {{ review.evaluators_count === 1 ? 'Evaluador' : 'Evaluadores' }}</span></td>
                                <td class="px-6 py-4"><span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-white text-xs font-bold shadow-sm border border-gray-100" :class="{ 'text-yellow-700': review.status === 'pending', 'text-green-700': review.status === 'approved', 'text-red-700': review.status === 'rejected' }"><span class="h-2 w-2 rounded-full" :class="{ 'bg-yellow-500': review.status === 'pending', 'bg-green-500': review.status === 'approved', 'bg-red-500': review.status === 'rejected' }" />{{ statusLabel(review.status) }}</span></td>
                                <td class="px-6 py-4 text-center"><Link :href="route('national-review.show', review.id)" class="inline-flex items-center justify-center gap-2 px-4 py-2 border border-[var(--institutional-primary)] rounded-lg text-[var(--institutional-primary)] hover:bg-[var(--institutional-primary)] hover:text-white text-xs font-bold uppercase shadow-sm transition whitespace-nowrap"><Eye class="h-4 w-4" aria-hidden="true" />Ver detalles</Link></td>
                            </tr>
                            <tr v-if="!reviews.data.length"><td colspan="7" class="px-6 py-12 text-center text-gray-500">No hay solicitudes nacionales que coincidan con los filtros.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 bg-gray-50"><Pagination :links="reviews.links" :total="reviews.total" :from="reviews.from" :to="reviews.to" /></div>
            </div>
        </div>
    </DynamicLayout>
</template>

<style scoped>
:deep(.vue-select-custom .vs__dropdown-toggle) {
    background: linear-gradient(to bottom, #ffffff 0%, #f9fafb 100%);
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.5rem;
    min-height: 45px;
}

:deep(.vue-select-custom .vs__selected-options) {
    flex-wrap: nowrap !important;
    min-width: 0;
    flex: 1;
    display: flex;
    align-items: center;
    padding: 0 4px;
}

:deep(.vue-select-custom .vs__selected) {
    color: #374151;
    font-weight: 500;
}

:deep(.vue-select-custom .vs__search) {
    color: #111827;
}

:deep(.vue-select-custom .vs__search::placeholder) {
    color: #9ca3af;
}

:deep(.vue-select-custom .vs__dropdown-menu) {
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

:deep(.vue-select-custom .vs__dropdown-option) {
    padding: 0.75rem 1rem;
    color: #374151;
}

:deep(.vue-select-custom .vs__dropdown-option--highlight) {
    background: #1B396A;
    color: white;
}

:deep(.vue-select-custom .vs__actions) {
    padding-right: 4px;
    display: flex;
    align-items: center;
}

:deep(.vue-select-custom .vs__open-indicator) {
    fill: #1B396A;
    transform: scale(0.8);
}
</style>
