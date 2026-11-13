<script setup>
import LayoutAuthenticated from '@/layouts/LayoutAuthenticated.vue';
import Pagination from '@/Shared/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Mail, Filter, ListChecks, Search, Eye, X } from 'lucide-vue-next';
const props = defineProps({ messages: Object, filters: Object, unreadCount: Number });
const search = ref(props.filters.search || '');
const status = ref(props.filters.status || '');
const rows = ref(props.filters.rows || 10);
const selected = ref(null);
const filter = () => router.get(route('superadmin.contact-messages.index'), { search: search.value, status: status.value, rows: rows.value }, { preserveState: true, preserveScroll: true, replace: true });
const clearFilters = () => { search.value = ''; status.value = ''; rows.value = 10; filter(); };
const open = (message) => {
    selected.value = message;
    if (!message.read_at) router.patch(route('superadmin.contact-messages.update', message.id), {}, { preserveScroll: true });
};
const date = (value) => new Date(value).toLocaleString('es-MX');
</script>
<template>
    <LayoutAuthenticated>
        <Head title="Mensajes de contacto" />

        <div class="space-y-6">
            <header>
                <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl">Mensajes de contacto</h1>
                <div class="mt-2 flex items-center gap-2 text-sm">
                    <Mail :size="16" class="text-[var(--institutional-primary)]" aria-hidden="true" />
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-700">{{ unreadCount }} sin leer</span>
                </div>
            </header>

            <form class="rounded-lg border border-gray-200 bg-white p-4 shadow-md" @submit.prevent="filter">
                <div class="mb-2 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="flex items-center gap-2 text-xl font-semibold text-gray-800">
                        <Filter :size="20" aria-hidden="true" />
                        Filtro de Búsqueda
                    </h2>
                    <button type="button" class="flex cursor-pointer items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50" @click="clearFilters">
                        <ListChecks :size="16" aria-hidden="true" />
                        Limpiar Filtros
                    </button>
                </div>
                <p class="mb-4 text-sm text-gray-500">Buscar por remitente, correo, institución o contenido del mensaje</p>
                <div class="flex flex-col gap-4 md:flex-row md:items-end">
                    <div class="min-w-0 flex-1">
                        <label for="contact-search" class="mb-1 block text-sm font-medium text-gray-700">Buscar</label>
                        <div class="flex gap-2">
                            <div class="relative min-w-0 flex-1">
                                <Search :size="20" class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-[var(--institutional-primary)]" aria-hidden="true" />
                                <input id="contact-search" v-model="search" type="search" placeholder="Buscar mensajes..." class="h-[45px] w-full rounded-lg border border-gray-300 pr-3 pl-10 text-sm text-gray-700 transition focus:ring-2 focus:ring-[var(--institutional-primary)]" />
                            </div>
                            <button type="submit" class="cursor-pointer rounded-lg border border-gray-300 px-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50">Buscar</button>
                        </div>
                    </div>
                    <div class="md:w-52">
                        <label for="contact-status" class="mb-1 block text-sm font-medium text-gray-700">Estado</label>
                        <select id="contact-status" v-model="status" class="h-[45px] w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-700 transition focus:ring-2 focus:ring-[var(--institutional-primary)]" @change="filter">
                            <option value="">Todos los mensajes</option>
                            <option value="unread">Sin leer</option>
                            <option value="read">Leídos</option>
                        </select>
                    </div>
                    <div class="md:w-52">
                        <label for="contact-rows" class="mb-1 block text-sm font-medium text-gray-700">Registros</label>
                        <select id="contact-rows" v-model="rows" class="h-[45px] w-full rounded-lg border border-gray-300 px-3 text-sm text-gray-700 transition focus:ring-2 focus:ring-[var(--institutional-primary)]" @change="filter">
                            <option v-for="count in [5, 10, 25, 50]" :key="count" :value="count">{{ count }} Registros</option>
                        </select>
                    </div>
                </div>
            </form>

            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-md">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-[var(--institutional-primary)] text-xs font-semibold text-white uppercase">
                            <tr>
                                <th scope="col" class="px-6 py-4 tracking-wider">Remitente</th>
                                <th scope="col" class="px-6 py-4 tracking-wider">Institución</th>
                                <th scope="col" class="px-6 py-4 tracking-wider">Fecha</th>
                                <th scope="col" class="px-6 py-4 tracking-wider">Estado</th>
                                <th scope="col" class="px-6 py-4 text-center tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="message in messages.data" :key="message.id" class="transition hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-gray-800">{{ message.name }}</div>
                                    <div class="mt-1 text-gray-500 [overflow-wrap:anywhere]">{{ message.email }}</div>
                                </td>
                                <td class="px-6 py-4 text-gray-600">{{ message.institution_name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-gray-600">{{ date(message.created_at) }}</td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap"
                                        :class="message.read_at ? 'bg-gray-100 text-gray-700' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'"
                                    >
                                        <span class="h-2 w-2 rounded-full bg-current" aria-hidden="true"></span>
                                        {{ message.read_at ? 'Leído' : 'Sin leer' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button
                                        type="button"
                                        class="inline-flex cursor-pointer items-center justify-center rounded-full border border-current p-2 text-[var(--institutional-primary)] transition hover:bg-[var(--institutional-primary)] hover:text-white"
                                        title="Ver mensaje"
                                        :aria-label="`Ver mensaje de ${message.name}`"
                                        @click="open(message)"
                                    >
                                        <Eye :size="20" aria-hidden="true" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!messages.data.length">
                                <td colspan="5" class="px-6 py-12 text-center text-gray-500">No hay mensajes para mostrar.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="overflow-x-auto border-t border-gray-200 bg-gray-50 px-4 py-4 sm:px-6">
                    <Pagination :links="messages.links" :total="messages.total" :from="messages.from" :to="messages.to" />
                </div>
            </div>

            <section v-if="selected" class="rounded-lg border border-gray-200 bg-white p-4 shadow-md" aria-labelledby="message-title">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="message-title" class="text-xl font-semibold text-gray-800">Mensaje de {{ selected.name }}</h2>
                    <button type="button" class="flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50" @click="selected = null">
                        <X :size="16" aria-hidden="true" />
                        Cerrar
                    </button>
                </div>
                <p class="mt-2 text-sm text-gray-500 [overflow-wrap:anywhere]">{{ selected.email }} · {{ selected.institution_name }} · {{ date(selected.created_at) }}</p>
                <p class="mt-4 leading-relaxed whitespace-pre-wrap text-gray-800 [overflow-wrap:anywhere]">{{ selected.message }}</p>
            </section>
        </div>
    </LayoutAuthenticated>
</template>
