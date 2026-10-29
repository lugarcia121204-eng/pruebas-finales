<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue';
import Pagination from '@/Shared/Pagination.vue';
import VueSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { alertaExito, alertaError } from '@/utils/alerts.js';

const props = defineProps({ applications: Object, institutions: Array, filters: Object, nationalAdministrators: Array });
const assignments = ref({});
const assigning = ref(null);
const assign = (application) => {
    const administratorId = assignments.value[application.id];
    if (application.national_administrator_id || !administratorId || assigning.value !== null) return;
    assigning.value = application.id;
    router.post(route('superadmin.national-stage.assign', application.id), { administrator_id: administratorId }, {
        preserveScroll: true,
        onSuccess: () => alertaExito('Asignación completada', 'La solicitud está pendiente de revisión nacional.'),
        onError: (errors) => alertaError('No se pudo asignar', errors.administrator_id || 'Verifica la solicitud e intenta de nuevo.'),
        onFinish: () => assigning.value = null,
    });
};
const nationalStatus = (status) => ({ unassigned: 'Sin asignar', pending: 'Pendiente de revisión nacional', approved: 'Aprobada en etapa nacional', rejected: 'Rechazada en etapa nacional' }[status] ?? status);
const search = ref(props.filters.search ?? '');
const institutionId = ref(props.filters.institution_id ?? '');
const applyFilters = () => router.get(route('superadmin.national-stage.index'), {
    search: search.value || undefined,
    institution_id: institutionId.value || undefined,
}, { preserveState: true, replace: true });
const clearFilters = () => {
    search.value = '';
    institutionId.value = '';
    applyFilters();
};
</script>

<template>
    <LayoutAuthenticated>
        <Head title="Etapa nacional" />
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Etapa nacional</h1>
                <p class="mt-2 text-gray-600">Solicitudes aprobadas por los administradores de las instituciones.</p>
            </div>
            <form @submit.prevent="applyFilters" class="bg-white rounded-lg border border-gray-200 shadow-sm p-5 space-y-4">
                <h2 class="font-semibold text-gray-900">Buscar solicitudes</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="national-search" class="block text-sm font-medium mb-2">Docente, correo, institución o ID</label>
                        <input id="national-search" v-model="search" type="search" placeholder="Buscar solicitudes..." class="w-full rounded-lg border border-gray-300 p-2.5" />
                    </div>
                    <div>
                        <label for="national-institution" class="block text-sm font-medium mb-2">Institución</label>
                        <select id="national-institution" v-model="institutionId" class="w-full rounded-lg border border-gray-300 p-2.5">
                            <option value="">Todas las instituciones</option>
                            <option v-for="institution in institutions" :key="institution.id" :value="institution.id">{{ institution.name }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="rounded-lg bg-[var(--institutional-primary)] px-5 py-2 text-white">Buscar</button>
                    <button type="button" @click="clearFilters" class="rounded-lg border border-gray-300 px-5 py-2">Limpiar filtros</button>
                </div>
            </form>
            <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
                <div class="p-5 font-semibold text-gray-900">Solicitudes aprobadas: {{ applications.total }}</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-[var(--institutional-primary)] text-white uppercase text-xs">
                            <tr>
                                <th scope="col" class="px-6 py-4">ID</th>
                                <th scope="col" class="px-6 py-4">Docente</th>
                                <th scope="col" class="px-6 py-4">Institución</th>
                                <th scope="col" class="px-6 py-4">Convocatoria</th>
                                <th scope="col" class="px-6 py-4">Asignar</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="application in applications.data" :key="application.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4">{{ application.id }}</td>
                                <td class="px-6 py-4 font-medium">{{ application.teacher }}</td>
                                <td class="px-6 py-4 min-w-56">{{ application.institution }}</td>
                                <td class="px-6 py-4">{{ application.announcement }}</td>
                                <td class="px-6 py-4 min-w-80">
                                    <span class="mb-2 inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold" :class="{ 'bg-green-50 text-green-700': application.national_status === 'approved', 'bg-red-50 text-red-700': application.national_status === 'rejected', 'bg-amber-50 text-amber-800': application.national_status === 'pending', 'bg-gray-100 text-gray-600': application.national_status === 'unassigned' }"><span class="h-2 w-2 rounded-full" :class="{ 'bg-green-500': application.national_status === 'approved', 'bg-red-500': application.national_status === 'rejected', 'bg-amber-500': application.national_status === 'pending', 'bg-gray-400': application.national_status === 'unassigned' }" aria-hidden="true"></span>{{ nationalStatus(application.national_status) }}</span>
                                    <form v-if="!application.national_administrator_id && application.national_status === 'unassigned'" @submit.prevent="assign(application)" class="space-y-2">
                                        <VueSelect v-model="assignments[application.id]" :options="nationalAdministrators" :reduce="admin => admin.id" label="name" :input-id="`national-admin-${application.id}`" :aria-label="`Admin Nacional para solicitud ${application.id}`" placeholder="Seleccionar Admin Nacional..." append-to-body>
                                            <template #no-options>No hay administradores nacionales disponibles.</template>
                                        </VueSelect>
                                        <p v-if="application.national_administrator" class="text-xs text-gray-600">Asignado a: {{ application.national_administrator }}</p>
                                        <button type="submit" :disabled="!assignments[application.id] || assigning !== null" class="rounded-lg bg-[var(--institutional-primary)] px-4 py-2 text-white disabled:opacity-50">{{ assigning === application.id ? 'Asignando...' : 'Asignar' }}</button>
                                    </form>
                                    <div v-else>
                                        <p class="font-medium">{{ application.national_administrator }}</p>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!applications.data.length">
                                <td colspan="5" class="px-6 py-12 text-center text-gray-500">No hay solicitudes aprobadas que coincidan con los filtros.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-gray-200 px-6 py-4">
                    <Pagination :links="applications.links" :total="applications.total" :from="applications.from" :to="applications.to" />
                </div>
            </div>
        </div>
    </LayoutAuthenticated>
</template>
