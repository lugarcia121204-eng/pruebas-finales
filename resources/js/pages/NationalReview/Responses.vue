<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ChevronRight, CircleCheck, FileText } from 'lucide-vue-next';
import DynamicLayout from '@/layouts/DynamicLayout.vue';

defineProps({
    review: { type: Object, required: true },
    evaluation: { type: Object, required: true },
    responses: { type: Array, required: true },
});

const statuses = {
    approved: { label: 'Aprobada', text: 'text-green-700', dot: 'bg-green-500' },
    rejected: { label: 'Rechazada', text: 'text-red-700', dot: 'bg-red-500' },
    pending: { label: 'Revisión pendiente', text: 'text-yellow-700', dot: 'bg-yellow-500' },
};
</script>

<template>
    <DynamicLayout>
        <Head title="Respuestas del Evaluador" />

        <div class="space-y-6">
            <header class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl">Respuestas del Evaluador</h1>
                    <nav aria-label="Ubicación" class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                        <FileText class="h-4 w-4 text-[var(--institutional-primary)]" aria-hidden="true" />
                        <Link :href="route('national-review.index')" class="text-gray-700 hover:underline">Solicitudes</Link>
                        <ChevronRight class="h-4 w-4 text-gray-400" aria-hidden="true" />
                        <Link :href="route('national-review.show', review.id)" class="text-gray-700 hover:underline">Detalles de solicitud</Link>
                        <ChevronRight class="h-4 w-4 text-gray-400" aria-hidden="true" />
                        <span aria-current="page" class="font-semibold text-gray-900">Respuestas</span>
                    </nav>
                </div>
                <Link :href="route('national-review.show', review.id)" class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2 font-medium text-gray-700 transition hover:bg-gray-50">
                    <ArrowLeft class="h-5 w-5" aria-hidden="true" /> Regresar
                </Link>
            </header>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm sm:p-8">
                <div class="mb-6 flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-4">
                    <h2 class="text-lg font-bold text-gray-900">Datos de la Evaluación</h2>
                    <span v-if="statuses[review.status]" :class="statuses[review.status].text" class="inline-flex items-center gap-1.5 rounded-md border border-gray-100 bg-white px-3 py-1.5 text-xs font-bold shadow-sm">
                        <span :class="statuses[review.status].dot" class="h-2 w-2 rounded-full" aria-hidden="true"></span>
                        {{ statuses[review.status].label }}
                    </span>
                </div>
                <dl class="grid grid-cols-1 gap-6 sm:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <dt class="mb-1 text-xs font-bold uppercase tracking-wider text-[var(--institutional-primary)]">Evaluador asignado</dt>
                        <dd class="font-medium text-gray-900">{{ evaluation.evaluator || 'Sin nombre registrado' }}</dd>
                    </div>
                    <div>
                        <dt class="mb-1 text-xs font-bold uppercase tracking-wider text-[var(--institutional-primary)]">Docente</dt>
                        <dd class="font-medium text-gray-900">{{ review.teacher || 'Sin nombre registrado' }}</dd>
                    </div>
                    <div>
                        <dt class="mb-1 text-xs font-bold uppercase tracking-wider text-[var(--institutional-primary)]">Instituto</dt>
                        <dd class="font-medium text-gray-900">{{ review.institution || 'Sin institución registrada' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="mb-1 text-xs font-bold uppercase tracking-wider text-[var(--institutional-primary)]">Tipo de plaza</dt>
                        <dd class="font-medium text-gray-900">{{ review.position_type || 'No especificado' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="flex flex-col gap-3 bg-[var(--institutional-primary)] px-4 py-4 text-white sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div>
                        <h2 class="text-lg font-bold">Criterios Seleccionados</h2>
                        <p class="text-sm text-blue-100">Vista solo lectura de las calificaciones emitidas</p>
                    </div>
                    <div class="sm:text-right">
                        <p class="text-xs uppercase tracking-widest text-blue-100">Puntuación total</p>
                        <p class="text-2xl font-bold sm:text-3xl">
                            <template v-if="evaluation.score != null">{{ Number(evaluation.score) }} <span class="text-base font-normal">pts</span></template>
                            <template v-else>Sin puntaje</template>
                        </p>
                    </div>
                </div>
                <div class="space-y-6 p-4 sm:p-6">
                    <p v-if="!responses.length" class="py-6 text-center text-gray-500">No hay respuestas registradas.</p>
                    <ol v-else class="space-y-6">
                        <li v-for="(response, index) in responses" :key="response.id" class="border-b border-gray-100 pb-6 last:border-0 last:pb-0">
                            <span class="mb-1 block text-xs font-bold uppercase tracking-wider text-[var(--institutional-primary)]">Criterio {{ index + 1 }}</span>
                            <h3 class="mb-3 text-lg font-medium text-gray-900">{{ response.question }}</h3>
                            <div v-if="response.answer != null" class="flex items-center gap-3 rounded-lg border border-blue-500 bg-blue-50 p-4 text-blue-900">
                                <CircleCheck class="h-5 w-5 shrink-0 text-blue-600" aria-hidden="true" />
                                <div class="flex flex-1 flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm font-bold">{{ response.answer }}</span>
                                    <span v-if="response.score != null" class="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-bold text-blue-800">{{ response.score }} pts</span>
                                </div>
                            </div>
                            <p v-else class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm italic text-red-700">La respuesta de este criterio no está disponible.</p>
                        </li>
                    </ol>
                    <div v-if="evaluation.comment" class="border-t border-gray-100 pt-4">
                        <h3 class="font-semibold text-gray-900">Observaciones</h3>
                        <p class="mt-1 whitespace-pre-wrap break-words text-sm text-gray-700">{{ evaluation.comment }}</p>
                    </div>
                </div>
            </section>
        </div>
    </DynamicLayout>
</template>

