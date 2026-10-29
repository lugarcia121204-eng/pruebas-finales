<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, ChevronRight, ClipboardCheck, Save, CheckCircle2, Clock } from 'lucide-vue-next';
import DocumentPreviewCards from '@/components/DocumentPreviewCards.vue';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import { alertaExito } from '@/utils/alerts.js';
const props = defineProps({ evaluation: Object, reviewStatus: String, teacher: String, rubric: Object, documents: Array, applicant: { type: Object, default: () => ({}) } });
const formatDate = (date) => date ? new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Mexico_City' }) : 'No disponible';
const canEdit = computed(() => props.evaluation.status === 'pending' && props.reviewStatus === 'pending');
const form = useForm({ answers: props.evaluation.answers ?? {}, comment: props.evaluation.comment ?? '' });
const selectedScore = computed(() => (props.rubric?.questions ?? []).reduce((total, question) => total + Number(question.options.find(option => String(option.id) === String(form.answers[question.id]))?.score ?? 0), 0));
const submit = () => form.post(route('national-evaluations.submit', props.evaluation.id), { preserveScroll: true, onSuccess: () => alertaExito('Evaluación registrada', 'La evaluación nacional se guardó correctamente.') });
</script>

<template>
    <DynamicLayout>
        <Head title="Evaluación nacional" />
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Detalles de Evaluación Nacional</h1>
                    <nav aria-label="Ubicación" class="flex flex-wrap items-center gap-2 mt-2 text-sm">
                        <ClipboardCheck class="w-4 h-4 text-[var(--institutional-primary)]" aria-hidden="true" />
                        <Link :href="route('national-evaluations.index')" class="text-gray-600 hover:underline">Evaluaciones nacionales</Link>
                        <ChevronRight class="w-3 h-3 text-gray-400" aria-hidden="true" />
                        <span class="font-semibold text-gray-900">Detalle de evaluación</span>
                    </nav>
                </div>
                <Link :href="route('national-evaluations.index')" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-2 font-medium text-gray-700 hover:bg-gray-50 transition"><ArrowLeft class="h-5 w-5" aria-hidden="true" />Regresar</Link>
            </div>
            <div class="flex items-center gap-4 px-5 py-4 rounded-lg bg-white shadow-sm border border-gray-100 border-l-[5px]" :class="evaluation.status === 'pending' ? 'border-l-amber-500' : 'border-l-green-600'">
                <Clock v-if="evaluation.status === 'pending'" class="w-7 h-7 shrink-0 text-amber-500" aria-hidden="true" /><CheckCircle2 v-else class="w-7 h-7 shrink-0 text-green-600" aria-hidden="true" />
                <div><p class="text-xs font-bold uppercase tracking-wider mb-0.5" :class="evaluation.status === 'pending' ? 'text-amber-700' : 'text-green-700'">Estado de Evaluación</p><h2 class="font-bold text-gray-900">{{ evaluation.status === 'pending' ? 'Evaluación Pendiente' : 'Evaluación Completada' }}</h2><p class="text-sm text-gray-500 mt-0.5">{{ evaluation.status === 'pending' ? 'Asignada el' : 'Evaluada el' }} {{ formatDate(evaluation.status === 'pending' ? evaluation.created_at : evaluation.updated_at) }}</p></div>
            </div>
            <section class="rounded-xl border border-gray-100 bg-white p-4 sm:p-6 shadow-sm">
                <div class="border-b border-gray-100 pb-4 mb-6">
                    <h2 class="text-lg font-bold text-gray-900">Información General</h2>
                </div>
                <dl class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Docente</dt><dd class="font-medium text-gray-900">{{ teacher || 'No registrado' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Institución</dt><dd class="font-medium text-gray-900">{{ applicant.institution || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Convocatoria</dt><dd class="font-medium text-gray-900">{{ applicant.announcement || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Área de Procedencia</dt><dd class="font-medium text-gray-900">{{ applicant.priority_area || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Subárea</dt><dd class="font-medium text-gray-900">{{ applicant.sub_area || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Fecha de Solicitud</dt><dd class="font-medium text-gray-900">{{ formatDate(applicant.application_date) }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Tipo de Plaza</dt><dd class="font-medium text-gray-900">{{ applicant.position_type || 'No especificado' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-2">Vía de Solicitud</dt><dd><span v-if="['larga', 'corta'].includes(applicant.via)" class="inline-flex px-4 py-2.5 rounded-lg text-xs font-bold uppercase tracking-wider text-white shadow-md" :class="applicant.via === 'larga' ? 'bg-[var(--institutional-primary)]' : 'bg-green-700'">{{ applicant.via === 'larga' ? 'Vía Larga' : 'Vía Corta' }}</span><span v-else class="text-gray-600">No especificada</span></dd></div>
                </dl>
            </section>
            <DocumentPreviewCards :documents="documents" route-name="national-evaluations.document" :owner-id="evaluation.id" />
            <form @submit.prevent="submit" class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
                <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="bg-[var(--institutional-primary)] px-6 py-4 flex flex-wrap justify-between items-center gap-3 text-white">
                    <div><h2 class="font-bold text-lg">Rúbrica de Evaluación</h2><p class="text-blue-100 text-sm">{{ canEdit ? 'Selecciona una opción para cada criterio' : (rubric?.title ?? 'Evaluación registrada') }}</p></div>
                    <div class="text-right"><p class="text-xs opacity-80 uppercase tracking-widest">Puntuación Total</p><p class="text-3xl font-bold">{{ evaluation.status === 'pending' ? selectedScore : evaluation.score }} <span class="text-lg font-normal">pts</span></p></div>
                </div>
                <div class="p-4 sm:p-6 space-y-8">
                    <p v-if="!rubric" class="text-gray-500 text-center py-8">No hay una rúbrica asignada.</p>
                <fieldset v-for="(question, index) in rubric?.questions ?? []" :key="question.id" :disabled="!canEdit" class="border-b border-gray-100 pb-6 last:border-0 last:pb-0 space-y-3">
                    <legend class="mb-3"><span class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1 block">Criterio {{ index + 1 }}</span><span class="text-lg font-medium text-gray-900">{{ question.text }}</span></legend>
                    <label v-for="option in question.options" :key="option.id" class="flex items-start gap-3 rounded-lg border p-4 transition" :class="[String(form.answers[question.id]) === String(option.id) ? 'border-[var(--institutional-primary)] bg-blue-50/60' : 'border-gray-200 bg-white', canEdit ? 'cursor-pointer hover:border-[var(--institutional-primary)]' : 'cursor-default']"><input type="radio" :name="`question-${question.id}`" v-model="form.answers[question.id]" :value="option.id" required class="mt-1 shrink-0 accent-[var(--institutional-primary)]" /><span class="flex flex-wrap items-center justify-between gap-2 w-full"><span class="text-gray-900">{{ option.text }}</span><span class="rounded-md bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">{{ option.score }} puntos</span></span></label>
                </fieldset>
                </div>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 space-y-5 lg:sticky lg:top-6">
                <h2 class="font-bold text-lg text-gray-900 border-b border-gray-100 pb-3">Resumen de Evaluación</h2>
                <div><label for="evaluation-comment" class="block mb-2 font-medium text-gray-900">Observaciones</label>
                <textarea id="evaluation-comment" v-model="form.comment" :disabled="!canEdit" maxlength="1000" rows="4" placeholder="Escribe tus observaciones sobre la evaluación..." class="w-full rounded-lg border border-gray-300 bg-gray-50 p-3 focus:border-[var(--institutional-primary)] focus:ring-[var(--institutional-primary)] disabled:opacity-100" /></div>
                <p v-for="(error, field) in form.errors" :key="field" class="text-red-600">{{ error }}</p>
                <div class="flex flex-col sm:flex-row sm:items-center justify-end gap-3 border-t border-gray-200 pt-5">
                    <template v-if="canEdit"><Link :href="route('national-evaluations.index')" class="rounded-lg border border-gray-300 px-5 py-2.5 text-center font-medium text-gray-700 hover:bg-gray-50">Cancelar</Link><button :disabled="form.processing || !rubric" class="inline-flex items-center justify-center gap-2 rounded-lg bg-[var(--institutional-primary)] px-5 py-2.5 font-medium text-white shadow-sm hover:bg-[#0f2347] disabled:opacity-50"><Save class="w-4 h-4" aria-hidden="true" />{{ form.processing ? 'Guardando...' : 'Guardar evaluación nacional' }}</button></template>
                    <p v-else-if="evaluation.status !== 'pending'" class="inline-flex items-center gap-2 rounded-lg bg-green-50 px-4 py-3 font-medium text-green-800"><CheckCircle2 class="h-5 w-5" aria-hidden="true" />Evaluación registrada. Puntaje: {{ evaluation.score }}</p>
                    <p v-else class="rounded-lg bg-gray-100 px-4 py-3 text-gray-600">La revisión nacional ha concluido.</p>
                </div>
                </div>
            </form>
        </div>
    </DynamicLayout>
</template>
