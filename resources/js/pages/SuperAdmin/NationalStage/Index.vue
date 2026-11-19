<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue';
import Pagination from '@/Shared/Pagination.vue';
import VueSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { alertaExito, alertaError } from '@/utils/alerts.js';
import { Check, CheckCircle2, Search, Trash2, UserRound, Users } from 'lucide-vue-next';

const props = defineProps({ applications: Object, institutions: Array, filters: Object, nationalAdministrators: Array });
const selectedAdministrator = ref(null);
const selectedApplications = ref([]);
const selectedIds = computed(() => selectedApplications.value.map(application => application.id));
const selectableApplications = computed(() =>
    props.applications.data.filter(application => application.national_status === 'unassigned'),
);
const allVisibleSelected = computed(() =>
    selectableApplications.value.length > 0
    && selectableApplications.value.every(application => selectedIds.value.includes(application.id)),
);
const bulkAssigning = ref(false);
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
const toggleApplication = (application) => {
    if (application.national_status !== 'unassigned') return;

    if (selectedIds.value.includes(application.id)) {
        selectedApplications.value = selectedApplications.value.filter(item => item.id !== application.id);
        return;
    }

    selectedApplications.value = [...selectedApplications.value, application];
};
const toggleVisibleApplications = () => {
    if (allVisibleSelected.value) {
        const visibleIds = selectableApplications.value.map(application => application.id);
        selectedApplications.value = selectedApplications.value.filter(item => !visibleIds.includes(item.id));
        return;
    }

    const selected = new Set(selectedIds.value);
    selectedApplications.value = [
        ...selectedApplications.value,
        ...selectableApplications.value.filter(application => !selected.has(application.id)),
    ];
};
const removeSelectedApplication = (id) => {
    selectedApplications.value = selectedApplications.value.filter(application => application.id !== id);
};
const assignSelectedApplications = () => {
    if (!selectedAdministrator.value || !selectedIds.value.length || bulkAssigning.value) return;

    bulkAssigning.value = true;
    router.post(route('superadmin.national-stage.assign-many'), {
        administrator_id: selectedAdministrator.value,
        application_ids: selectedIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            selectedApplications.value = [];
            alertaExito('Asignación completada', 'Las solicitudes se asignaron al administrador nacional seleccionado.');
        },
        onError: (errors) => alertaError(
            'No se pudo completar la asignación',
            errors.application_ids || errors.administrator_id || 'Actualiza la lista e intenta de nuevo.',
        ),
        onFinish: () => { bulkAssigning.value = false; },
    });
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
                        <label for="national-search" class="block text-sm font-medium mb-2">Nombre del docente, institución o ID</label>
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
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm" aria-labelledby="national-assignment-title">
                <div class="flex flex-col gap-5 border-b border-gray-200 bg-gray-50 p-5 sm:flex-row sm:items-end sm:justify-between sm:p-6">
                    <div class="sm:max-w-sm">
                        <h2 id="national-assignment-title" class="text-lg font-bold text-gray-900">Administrador Nacional seleccionado</h2>
                        <p class="mt-1 text-sm text-gray-600">Elige a quién asignar las solicitudes seleccionadas.</p>
                    </div>
                    <div class="w-full sm:flex sm:min-w-80 sm:max-w-2xl sm:flex-1 sm:items-center sm:gap-4">
                        <label for="bulk-national-administrator" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-gray-600 sm:mb-0 sm:whitespace-nowrap">
                            Asignar a
                        </label>
                        <div class="sm:min-w-0 sm:flex-1">
                            <VueSelect
                                v-model="selectedAdministrator"
                                :options="nationalAdministrators"
                                :reduce="administrator => administrator.id"
                                label="name"
                                input-id="bulk-national-administrator"
                                aria-label="Administrador Nacional seleccionado"
                                placeholder="Seleccionar administrador nacional..."
                                class="national-admin-select"
                                :clearable="false"
                                append-to-body
                            >
                                <template #no-options>No hay administradores nacionales disponibles.</template>
                            </VueSelect>
                        </div>
                    </div>
                </div>

                <div class="grid min-w-0 gap-5 p-4 lg:grid-cols-[minmax(0,1.45fr)_1px_minmax(18rem,0.9fr)] lg:gap-6 lg:p-5">
                    <section class="min-w-0 overflow-hidden rounded-xl border border-gray-200" aria-labelledby="national-approved-title">
                        <header class="flex flex-wrap items-center justify-between gap-3 bg-[var(--institutional-primary)] px-4 py-4 text-white sm:px-5">
                            <div class="flex items-center gap-3">
                                <Users class="size-5 shrink-0" aria-hidden="true" />
                                <h3 id="national-approved-title" class="font-bold">Solicitudes aprobadas en etapa local ({{ applications.total }})</h3>
                            </div>
                            <button
                                type="button"
                                :disabled="!selectableApplications.length"
                                class="inline-flex items-center gap-2 rounded-lg border border-white/50 px-3 py-2 text-sm font-medium transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="toggleVisibleApplications"
                            >
                                <CheckCircle2 class="size-4" aria-hidden="true" />
                                {{ allVisibleSelected ? 'Quitar selección de esta página' : 'Seleccionar todas de esta página' }}
                            </button>
                        </header>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-100 text-xs uppercase text-gray-700">
                                    <tr>
                                        <th scope="col" class="w-12 px-4 py-3">
                                            <input
                                                type="checkbox"
                                                aria-label="Seleccionar solicitudes disponibles de esta página"
                                                :checked="allVisibleSelected"
                                                :disabled="!selectableApplications.length"
                                                class="size-4 rounded border-gray-300 text-[var(--institutional-primary)] focus:ring-[var(--institutional-primary)]"
                                                @change="toggleVisibleApplications"
                                            />
                                        </th>
                                        <th scope="col" class="px-4 py-3">ID</th>
                                        <th scope="col" class="min-w-44 px-4 py-3">Docente</th>
                                        <th scope="col" class="min-w-36 px-4 py-3">Convocatoria</th>
                                        <th scope="col" class="min-w-44 px-4 py-3">Institución</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr
                                        v-for="application in applications.data"
                                        :key="application.id"
                                        class="transition-colors hover:bg-gray-50"
                                        :class="{ 'bg-blue-50/70': selectedIds.includes(application.id) }"
                                    >
                                        <td class="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                :aria-label="`Seleccionar solicitud de ${application.teacher}`"
                                                :checked="selectedIds.includes(application.id)"
                                                :disabled="application.national_status !== 'unassigned'"
                                                class="size-4 rounded border-gray-300 text-[var(--institutional-primary)] focus:ring-[var(--institutional-primary)] disabled:opacity-40"
                                                @change="toggleApplication(application)"
                                            />
                                        </td>
                                        <td class="px-4 py-3 font-medium text-gray-700">{{ application.id }}</td>
                                        <td class="px-4 py-3 font-medium text-gray-900">
                                            <div>{{ application.teacher }}</div>
                                            <span
                                                v-if="application.national_status !== 'unassigned'"
                                                class="mt-1 inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                                :class="{ 'bg-green-50 text-green-700': application.national_status === 'approved', 'bg-red-50 text-red-700': application.national_status === 'rejected', 'bg-amber-50 text-amber-800': application.national_status === 'pending' }"
                                            >
                                                <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                                {{ nationalStatus(application.national_status) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ application.announcement }}</td>
                                        <td class="px-4 py-3 text-gray-700">{{ application.institution }}</td>
                                    </tr>
                                    <tr v-if="!applications.data.length">
                                        <td colspan="5" class="px-4 py-12 text-center text-gray-500">No hay solicitudes aprobadas que coincidan con los filtros.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="border-t border-gray-200 px-4 py-4 sm:px-5">
                            <Pagination
                                :links="applications.links"
                                :total="applications.total"
                                :from="applications.from"
                                :to="applications.to"
                                :preserve-state="true"
                            />
                        </div>
                    </section>

                    <div class="hidden self-stretch bg-gray-200 lg:block" aria-hidden="true"></div>

                    <section class="flex min-w-0 flex-col overflow-hidden rounded-xl border border-gray-200" aria-labelledby="national-selected-title">
                        <header class="flex items-center justify-between gap-3 bg-[var(--institutional-primary)] px-4 py-4 text-white sm:px-5">
                            <div class="flex items-center gap-3">
                                <CheckCircle2 class="size-5 shrink-0" aria-hidden="true" />
                                <h3 id="national-selected-title" class="font-bold">Docentes seleccionados ({{ selectedApplications.length }})</h3>
                            </div>
                            <button
                                v-if="selectedApplications.length"
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-white/50 px-3 py-2 text-sm font-medium transition hover:bg-white/10"
                                @click="selectedApplications = []"
                            >
                                <Trash2 class="size-4" aria-hidden="true" />
                                Limpiar
                            </button>
                        </header>

                        <ul v-if="selectedApplications.length" class="max-h-[29rem] flex-1 divide-y divide-gray-200 overflow-y-auto px-4 sm:px-5">
                            <li v-for="application in selectedApplications" :key="application.id" class="flex items-center gap-3 py-4">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-blue-50 text-[var(--institutional-primary)]">
                                    <UserRound class="size-6" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold text-gray-900">{{ application.teacher }}</p>
                                    <p class="truncate text-sm text-gray-600">{{ application.announcement }} · {{ application.institution }}</p>
                                </div>
                                <button
                                    type="button"
                                    :aria-label="`Quitar a ${application.teacher} de la selección`"
                                    class="shrink-0 rounded-lg p-2 text-red-600 transition hover:bg-red-50"
                                    @click="removeSelectedApplication(application.id)"
                                >
                                    <Trash2 class="size-5" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                        <div v-else class="flex min-h-48 flex-1 flex-col items-center justify-center px-5 py-8 text-center text-gray-500">
                            <Users class="mb-3 size-9 text-gray-300" aria-hidden="true" />
                            <p class="font-medium">Aún no hay docentes seleccionados.</p>
                            <p class="mt-1 text-sm">Marca las solicitudes aprobadas que quieras asignar.</p>
                        </div>

                        <div class="m-4 flex items-start gap-3 rounded-lg bg-blue-50 p-4 text-sm text-blue-900 sm:m-5">
                            <Check class="mt-0.5 size-5 shrink-0 text-blue-700" aria-hidden="true" />
                            <p v-if="selectedApplications.length && selectedAdministrator">
                                Se asignarán {{ selectedApplications.length }} solicitudes al administrador nacional seleccionado.
                            </p>
                            <p v-else-if="selectedApplications.length">
                                Selecciona un administrador nacional para continuar.
                            </p>
                            <p v-else>
                                Selecciona solicitudes aprobadas y un administrador nacional.
                            </p>
                        </div>

                        <div class="flex flex-col-reverse gap-3 border-t border-gray-200 p-4 sm:flex-row sm:justify-end sm:p-5">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 px-4 py-2.5 font-medium text-gray-700 transition hover:bg-gray-50"
                                @click="selectedApplications = []"
                            >
                                Limpiar selección
                            </button>
                            <button
                                type="button"
                                :disabled="!selectedAdministrator || !selectedApplications.length || bulkAssigning"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-[var(--institutional-primary)] px-5 py-2.5 font-semibold text-white transition hover:bg-[var(--institutional-primary-dark)] disabled:cursor-not-allowed disabled:opacity-50"
                                @click="assignSelectedApplications"
                            >
                                <Check class="size-5" aria-hidden="true" />
                                {{ bulkAssigning ? 'Asignando...' : 'Asignar docentes' }}
                            </button>
                        </div>
                    </section>
                </div>
            </section>
        </div>
    </LayoutAuthenticated>
</template>

<style scoped>
.national-admin-select :deep(.vs__dropdown-toggle) {
    min-height: 48px;
    padding: 7px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 0.625rem;
    background: #fff;
    box-shadow: 0 1px 2px rgb(15 23 42 / 6%);
    transition: border-color 150ms ease, box-shadow 150ms ease;
}

.national-admin-select.vs--open :deep(.vs__dropdown-toggle),
.national-admin-select :deep(.vs__dropdown-toggle:focus-within) {
    border-color: var(--institutional-primary);
    box-shadow: 0 0 0 3px rgb(27 57 106 / 12%);
}

.national-admin-select :deep(.vs__selected),
.national-admin-select :deep(.vs__search) {
    margin: 0;
    padding: 0;
    color: #111827;
    font-size: 0.95rem;
}

.national-admin-select :deep(.vs__search::placeholder) {
    color: #64748b;
}
</style>
