<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Award, Search } from 'lucide-vue-next';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import Pagination from '@/Shared/Pagination.vue';
const props = defineProps({ recognitions: Object, filters: Object });
const search = ref(props.filters.search || '');
const rows = ref(props.filters.rows || 10);
const filter = () => router.get(route('national-review.recognitions.index'), { search: search.value || undefined, rows: rows.value }, { preserveState: true, replace: true });
const updating = ref(null);
const toggleError = ref('');
const toggle = (recognition) => {
    if (updating.value !== null) return;
    updating.value = recognition.id;
    toggleError.value = '';
    router.patch(route('national-review.recognitions.toggle', recognition.id), { active: !recognition.active }, {
        preserveScroll: true,
        onError: () => { toggleError.value = 'No se pudo actualizar el reconocimiento. Intenta de nuevo.'; },
        onFinish: () => { updating.value = null; },
    });
};
const clear = () => { search.value = ''; rows.value = 10; filter(); };
</script>

<template>
    <DynamicLayout>
        <Head title="Reconocimientos nacionales" />
        <div class="space-y-6">
            <div><h1 class="text-3xl font-bold text-gray-900">Reconocimientos</h1><p class="mt-2 flex items-center gap-2 text-gray-600"><Award class="h-5 w-5" />Docentes aprobados en tus revisiones nacionales</p></div>
            <form @submit.prevent="filter" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm space-y-4">
                <div class="flex items-center justify-between gap-3"><h2 class="text-xl font-bold">Filtro de Búsqueda</h2><button type="button" @click="clear" class="rounded-lg border border-gray-300 px-4 py-2">Limpiar filtros</button></div>
                <div class="flex flex-col gap-4 sm:flex-row">
                    <div class="relative flex-1"><Search class="absolute left-3 top-3 h-5 w-5 text-[var(--institutional-primary)]" /><input v-model="search" aria-label="Buscar docente o convocatoria" placeholder="Buscar por docente o convocatoria..." class="w-full rounded-lg border border-gray-300 py-3 pl-10 pr-3" /></div>
                    <select v-model="rows" @change="filter" aria-label="Registros por página" class="rounded-lg border border-gray-300 px-4 py-3"><option v-for="value in [5,10,25,50]" :key="value" :value="value">{{ value }} Registros</option></select>
                    <button class="rounded-lg bg-[var(--institutional-primary)] px-5 py-2 text-white">Buscar</button>
                </div>
            </form>
            <p v-if="toggleError" role="alert" class="text-red-600">{{ toggleError }}</p>
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="bg-[var(--institutional-primary)] text-xs uppercase text-white"><tr><th class="px-6 py-4">Docente</th><th class="px-6 py-4">Institución</th><th class="px-6 py-4">Convocatoria</th><th class="px-6 py-4">Folio</th><th class="px-6 py-4">Fecha</th><th class="px-6 py-4 text-center">Acciones</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="recognition in recognitions.data" :key="recognition.id" class="hover:bg-gray-50">
                            <td class="px-6 py-4 font-medium">{{ recognition.teacher }}</td><td class="px-6 py-4">{{ recognition.institution || 'Sin institución' }}</td><td class="px-6 py-4">{{ recognition.announcement }}</td><td class="px-6 py-4">{{ recognition.identifier || 'Pendiente de plantilla' }}</td><td class="px-6 py-4 whitespace-nowrap">{{ recognition.date }}</td>
                            <td class="px-6 py-4 text-center"><div class="flex flex-col items-center gap-3">
                                <button type="button" role="switch" :aria-checked="recognition.active" :aria-label="`Reconocimiento de ${recognition.teacher}: ${recognition.active ? 'activo' : 'inactivo'}`" :disabled="updating !== null" @click="toggle(recognition)" class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--institutional-primary)] disabled:cursor-wait disabled:opacity-60" :class="recognition.active ? 'bg-green-600' : 'bg-gray-300'"><span class="h-5 w-5 rounded-full bg-white shadow transition-transform" :class="recognition.active ? 'translate-x-6' : 'translate-x-1'"></span></button>
                                <span class="text-xs" :class="recognition.active ? 'text-green-700' : 'text-gray-500'">{{ recognition.active ? 'Activo' : 'Inactivo' }}</span>
</div></td>
                        </tr>
                        <tr v-if="!recognitions.data.length"><td colspan="6" class="px-6 py-10 text-center text-gray-500">No hay reconocimientos de docentes aprobados.</td></tr>
                    </tbody>
                </table></div>
                <div class="border-t border-gray-100 px-6 py-4"><Pagination :links="recognitions.links" :total="recognitions.total" :from="recognitions.from" :to="recognitions.to" /></div>
            </div>
        </div>
    </DynamicLayout>
</template>
