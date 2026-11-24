<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, Filter, Search } from 'lucide-vue-next';
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue';
import Pagination from '@/Shared/Pagination.vue';
import SearchableFilterSelect from '@/components/SearchableFilterSelect.vue';

const props = defineProps({ reviews: Object, filters: Object, announcements: Array, institutions: Array, nationalAdministrators: Array });
const search = ref(props.filters.search ?? '');
const announcementId = ref(props.filters.announcement_id ?? '');
const institutionId = ref(props.filters.institution_id ?? '');
const administratorId = ref(props.filters.administrator_id ?? '');
const rows = ref(Number(props.filters.rows ?? 10));
const applyFilters = () => router.get(route('superadmin.national-stage.assignments'), {
    search: search.value || undefined,
    announcement_id: announcementId.value || undefined,
    institution_id: institutionId.value || undefined,
    administrator_id: administratorId.value || undefined,
    rows: rows.value,
}, { preserveState: true, replace: true });
const clearFilters = () => {
    search.value = '';
    announcementId.value = '';
    institutionId.value = '';
    administratorId.value = '';
    rows.value = 10;
    applyFilters();
};
const statusLabel = status => ({ pending: 'Pendiente de revisión nacional', approved: 'Aprobada en etapa nacional', rejected: 'Rechazada en etapa nacional' }[status] ?? status);
</script>

<template>
    <LayoutAuthenticated>
        <Head title="Asignaciones nacionales" />
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Asignaciones nacionales</h1>
                    <p class="mt-2 text-gray-600">Consulta los docentes asignados a cada administrador nacional.</p>
                </div>
                <Link :href="route('superadmin.national-stage.index')" class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                    <ArrowLeft class="size-4" aria-hidden="true" />Volver a asignar solicitudes
                </Link>
            </div>

            <form @submit.prevent="applyFilters" class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-center gap-2"><Filter class="size-5 text-gray-700" aria-hidden="true" /><h2 class="text-lg font-bold text-gray-900">Filtros de búsqueda</h2></div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label for="assignment-teacher" class="mb-2 block text-sm font-medium text-gray-700">Nombre del docente</label>
                        <input id="assignment-teacher" v-model="search" type="search" placeholder="Buscar docente..." class="w-full rounded-lg border border-gray-300 p-2.5 text-sm" />
                    </div>
                    <div>
                        <label for="assignment-announcement" class="mb-2 block text-sm font-medium text-gray-700">Convocatoria</label>
                        <SearchableFilterSelect input-id="assignment-announcement" v-model="announcementId" :options="announcements" empty-label="Todas las convocatorias" />
                    </div>
                    <div>
                        <label for="assignment-institution" class="mb-2 block text-sm font-medium text-gray-700">Institución</label>
                        <SearchableFilterSelect input-id="assignment-institution" v-model="institutionId" :options="institutions" empty-label="Todas las instituciones" />
                    </div>
                    <div>
                        <label for="assignment-administrator" class="mb-2 block text-sm font-medium text-gray-700">Administrador nacional</label>
                        <SearchableFilterSelect input-id="assignment-administrator" v-model="administratorId" :options="nationalAdministrators" empty-label="Todos los administradores nacionales" />
                    </div>
                </div>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-[var(--institutional-primary)] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[var(--institutional-primary-dark)]"><Search class="size-4" aria-hidden="true" />Buscar</button>
                        <button type="button" @click="clearFilters" class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">Limpiar filtros</button>
                    </div>
                    <select v-model="rows" @change="applyFilters" aria-label="Registros por página" class="rounded-lg border border-gray-300 p-2.5 text-sm">
                        <option v-for="count in [5, 10, 25, 50]" :key="count" :value="count">{{ count }} Registros</option>
                    </select>
                </div>
            </form>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-200 px-6 py-4"><h2 class="text-lg font-bold text-gray-900">Solicitudes asignadas ({{ reviews.total }})</h2></div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead class="bg-[var(--institutional-primary)] text-xs uppercase text-white">
                            <tr><th scope="col" class="px-6 py-3">N.º</th><th scope="col" class="px-6 py-3">Docente</th><th scope="col" class="px-6 py-3">Convocatoria</th><th scope="col" class="px-6 py-3">Institución</th><th scope="col" class="px-6 py-3">Admin nacional asignado</th><th scope="col" class="px-6 py-3">Estado</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(review, index) in reviews.data" :key="review.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-gray-500">{{ (reviews.from ?? 1) + index }}</td>
                                <td class="px-6 py-4 font-medium text-gray-900">{{ review.teacher }}</td>
                                <td class="px-6 py-4 text-gray-700">{{ review.announcement }}</td>
                                <td class="px-6 py-4 text-gray-700">{{ review.institution }}</td>
                                <td class="px-6 py-4 text-gray-700">{{ review.administrator }}</td>
                                <td class="px-6 py-4"><span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold" :class="{ 'bg-amber-50 text-amber-800': review.status === 'pending', 'bg-green-50 text-green-700': review.status === 'approved', 'bg-red-50 text-red-700': review.status === 'rejected' }">{{ statusLabel(review.status) }}</span></td>
                            </tr>
                            <tr v-if="!reviews.data.length"><td colspan="6" class="px-6 py-12 text-center text-gray-500">No hay asignaciones nacionales que coincidan con los filtros.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="overflow-x-auto border-t border-gray-200 bg-gray-50 px-6 py-4"><Pagination :links="reviews.links" :total="reviews.total" :from="reviews.from" :to="reviews.to" /></div>
            </section>
        </div>
    </LayoutAuthenticated>
</template>
