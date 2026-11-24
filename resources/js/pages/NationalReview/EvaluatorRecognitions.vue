<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref, watch, onUnmounted } from 'vue';
import { debounce } from 'lodash';
import { Star, Search, Filter, ListFilter, ArrowDownUp } from 'lucide-vue-next';
import VueSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
const props = defineProps({ recognitions: Object, filters: Object });
const search = ref(props.filters.search ?? '');
const rows = ref(Number(props.filters.rows ?? 10));
const sortField = ref(props.filters.sort_field ?? '');
const sortDirection = ref(props.filters.sort_direction ?? 'asc');
const rowOptions = [5, 10, 25, 50].map(value => ({ label: `${value} Registros`, value }));
const updating = ref(null);
const error = ref('');
const filter = () => {
    onSearch.cancel();
    router.get(route('national-review.evaluator-recognitions.index'), {
        search: search.value || undefined, rows: rows.value,
        sort_field: sortField.value || undefined, sort_direction: sortField.value ? sortDirection.value : undefined,
    }, { preserveState: true, replace: true, preserveScroll: true });
};
const onSearch = debounce(filter, 500);
watch(search, () => onSearch(), { flush: 'sync' });
onUnmounted(() => onSearch.cancel());
const clear = () => { search.value = ''; rows.value = 10; sortField.value = ''; sortDirection.value = 'asc'; filter(); };
const sortBy = field => {
    sortDirection.value = sortField.value === field && sortDirection.value === 'asc' ? 'desc' : 'asc';
    sortField.value = field;
    filter();
};
const key = recognition => `${recognition.evaluator_id}-${recognition.announcement_id}`;
const toggle = recognition => {
    if (updating.value !== null || recognition.applications_reviewed === 0) return;
    updating.value = key(recognition);
    error.value = '';
    router.post(route('national-review.evaluator-recognitions.toggle'), {
        user_id: recognition.evaluator_id, announcement_id: recognition.announcement_id, active: !recognition.active,
    }, {
        preserveScroll: true,
        onError: errors => { error.value = Object.values(errors)[0] || 'No se pudo actualizar el reconocimiento.'; },
        onFinish: () => { updating.value = null; },
    });
};
</script>

<template>
    <DynamicLayout>
        <Head title="Reconocimientos" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Reconocimientos</h1>
                <div class="mt-2 flex items-center gap-2 text-sm font-semibold text-gray-900"><Star class="size-4 fill-current text-[var(--institutional-primary)]" aria-hidden="true" />Reconocimientos</div>
            </div>
            <form @submit.prevent="filter" class="rounded-lg border border-gray-200 bg-white p-4 shadow-md">
                <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-2"><Filter class="size-5 text-gray-700" aria-hidden="true" /><h2 class="text-xl font-semibold text-gray-800">Filtro de Búsqueda</h2></div>
                    <button type="button" @click="clear" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50"><ListFilter class="size-4" aria-hidden="true" />Limpiar Filtros</button>
                </div>
                <p class="mb-4 text-sm text-gray-500">Buscar evaluadores y convocatorias</p>
                <div class="flex flex-col items-end gap-4 md:flex-row">
                    <div class="relative w-full md:flex-1"><Search class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-[var(--institutional-primary)]" aria-hidden="true" /><input v-model="search" type="search" aria-label="Buscar evaluador o convocatoria" placeholder="Buscar..." class="h-[45px] w-full rounded-lg border border-gray-300 pl-10 pr-3 text-gray-700 transition hover:bg-gray-50 focus:border-[var(--institutional-primary)]" /></div>
                    <div class="w-full shrink-0 md:w-52"><VueSelect v-model="rows" :options="rowOptions" :reduce="option => option.value" :searchable="false" :clearable="false" placeholder="Registros" aria-label="Registros por página" class="vue-select-custom" @option:selected="filter" /></div>
                </div>
            </form>
            <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-md">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-left text-sm">
                        <thead class="bg-[var(--institutional-primary)] text-xs font-semibold uppercase text-white"><tr>
                            <th scope="col" class="px-6 py-4 tracking-wider">ID</th>
                            <th scope="col" class="px-6 py-4 tracking-wider" :aria-sort="sortField === 'evaluator_name' ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"><button type="button" @click="sortBy('evaluator_name')" class="flex items-center gap-1 uppercase transition hover:text-gray-200">Evaluador<ArrowDownUp class="size-4" :class="sortField === 'evaluator_name' ? 'opacity-100' : 'opacity-50'" aria-hidden="true" /></button></th>
                            <th scope="col" class="px-6 py-4 tracking-wider" :aria-sort="sortField === 'announcement_name' ? (sortDirection === 'asc' ? 'ascending' : 'descending') : 'none'"><button type="button" @click="sortBy('announcement_name')" class="flex items-center gap-1 uppercase transition hover:text-gray-200">Convocatoria<ArrowDownUp class="size-4" :class="sortField === 'announcement_name' ? 'opacity-100' : 'opacity-50'" aria-hidden="true" /></button></th>
                            <th scope="col" class="px-6 py-4 text-center tracking-wider">Acciones</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(recognition, index) in recognitions.data" :key="key(recognition)" class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-gray-900">{{ (recognitions.from ?? 1) + index }}</td>
                                <td class="px-6 py-4 font-semibold text-gray-900">{{ recognition.evaluator_name }}</td>
                                <td class="px-6 py-4 text-gray-700">{{ recognition.announcement_name }}</td>
                                <td class="px-6 py-4"><div class="flex items-center justify-center">
                                    <button type="button" role="switch" :aria-checked="recognition.active" :aria-label="`Reconocimiento de ${recognition.evaluator_name}: ${recognition.active ? 'activo' : 'inactivo'}`" :title="recognition.applications_reviewed === 0 ? 'El evaluador debe completar al menos una evaluación nacional de tus solicitudes.' : recognition.active ? 'Desactivar reconocimiento' : 'Activar reconocimiento'" :disabled="updating !== null || recognition.applications_reviewed === 0" @click="toggle(recognition)" class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--institutional-primary)] disabled:cursor-not-allowed disabled:opacity-50" :class="recognition.active ? 'bg-green-600' : 'bg-gray-300'"><span class="size-5 rounded-full bg-white shadow transition-transform" :class="recognition.active ? 'translate-x-6' : 'translate-x-1'" /></button>
                                </div></td>
                            </tr>
                            <tr v-if="!recognitions.data.length"><td colspan="4" class="px-6 py-12 text-center text-gray-500">No se encontraron registros</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="overflow-x-auto border-t border-gray-200 bg-gray-50 px-6 py-4"><Pagination :links="recognitions.links" :total="recognitions.total" :from="recognitions.from" :to="recognitions.to" /></div>
            </section>
        </div>
    </DynamicLayout>
</template>

<style scoped>
:deep(.vue-select-custom .vs__dropdown-toggle) { background:linear-gradient(to bottom,#fff,#f9fafb); border:1px solid #d1d5db; border-radius:0.5rem; padding:0.5rem; min-height:42px; }
:deep(.vue-select-custom .vs__selected) { color:#374151; font-weight:500; }
:deep(.vue-select-custom .vs__search) { color:#111827; }
:deep(.vue-select-custom .vs__dropdown-menu) { border:1px solid #d1d5db; border-radius:0.5rem; box-shadow:0 4px 6px -1px rgb(0 0 0 / 10%); }
:deep(.vue-select-custom .vs__dropdown-option) { padding:0.75rem 1rem; color:#374151; }
:deep(.vue-select-custom .vs__dropdown-option--highlight) { background:#1b396a; color:white; }
:deep(.vue-select-custom .vs__open-indicator) { fill:#1b396a; transform:scale(0.85); }
:deep(.vue-select-custom .vs__actions) { padding-right:4px; }
</style>
