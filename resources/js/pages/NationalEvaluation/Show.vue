<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ArrowLeft, ChevronRight, ClipboardCheck, Save, CheckCircle2, Clock } from 'lucide-vue-next';
import DocumentPreviewCards from '@/components/DocumentPreviewCards.vue';
import EvaluatorLayout from '@/layouts/EvaluatorLayout.vue';
import { alertaConfirmacionEscrita, alertaExito } from '@/utils/alerts.js';

const props = defineProps({
    evaluation: Object,
    reviewStatus: String,
    evaluationPeriodOpen: Boolean,
    teacher: String,
    rubric: Object,
    documents: Array,
    applicant: { type: Object, default: () => ({}) },
});

const formatDate = (date) => date
    ? new Date(date).toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Mexico_City' })
    : 'No disponible';

const isPending = computed(() => props.evaluation.status === 'pending');
const canEdit = computed(() => isPending.value && props.evaluationPeriodOpen && props.reviewStatus === 'pending');
const form = useForm({ answers: props.evaluation.answers ?? {}, comment: props.evaluation.comment ?? '' });
const confirming = ref(false);
const questions = computed(() => props.rubric?.questions ?? []);
const isSelected = (question, option) => String(form.answers[question.id]) === String(option.id);
const selectedOptions = computed(() => questions.value
    .map(question => question.options.find(option => isSelected(question, option)))
    .filter(Boolean));
const selectedScore = computed(() => selectedOptions.value.reduce((total, option) => total + Number(option.score), 0));
const progressPercentage = computed(() => questions.value.length
    ? selectedOptions.value.length / questions.value.length * 100
    : 0);

const submit = async () => {
    if (!canEdit.value || !props.rubric || confirming.value || form.processing) return;

    confirming.value = true;

    try {
        const confirmed = await alertaConfirmacionEscrita(
            '¿Enviar evaluación nacional?',
            `Se registrará la evaluación nacional con ${selectedScore.value} puntos. Una vez enviada, no podrás editarla.`,
            'CONFIRMAR',
        );

        if (confirmed) {
            form.post(route('national-evaluations.submit', props.evaluation.id), {
                preserveScroll: true,
                onSuccess: () => alertaExito('Evaluación registrada', 'La evaluación nacional se guardó correctamente.'),
            });
        }
    } finally {
        confirming.value = false;
    }
};
</script>

<template>
    <EvaluatorLayout>
        <Head title="Evaluación nacional" />

        <div class="space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">{{ isPending ? 'Evaluación Nacional de Solicitud' : 'Detalles de Evaluación Nacional' }}</h1>
                    <nav aria-label="Ubicación" class="flex flex-wrap items-center gap-2 mt-2 text-sm">
                        <ClipboardCheck class="w-4 h-4 shrink-0 text-[var(--institutional-primary)]" aria-hidden="true" />
                        <Link :href="route('national-evaluations.index')" class="text-gray-700 font-medium hover:text-[var(--institutional-primary)]">Evaluación Nacional</Link>
                        <ChevronRight class="w-4 h-4 text-gray-400" aria-hidden="true" />
                        <span class="text-gray-900 font-semibold">{{ isPending ? 'Evaluación' : 'Detalle de evaluación' }}</span>
                    </nav>
                </div>
                <Link :href="route('national-evaluations.index')" class="w-full sm:w-auto justify-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition flex items-center gap-2 font-medium">
                    <ArrowLeft class="h-5 w-5" aria-hidden="true" />
                    Regresar
                </Link>
            </div>

            <div class="flex items-center gap-4 px-5 py-4 rounded-lg bg-white shadow-sm border border-gray-100 border-l-[5px]" :class="isPending ? 'border-l-amber-500' : 'border-l-green-600'">
                <Clock v-if="isPending" class="w-6 h-6 shrink-0 text-amber-500" aria-hidden="true" />
                <CheckCircle2 v-else class="w-6 h-6 shrink-0 text-green-600" aria-hidden="true" />
                <div>
                    <p class="text-[10px] uppercase font-bold tracking-widest mb-0.5" :class="isPending ? 'text-amber-700' : 'text-green-700'">Estado de Evaluación</p>
                    <h2 class="text-sm font-bold leading-tight text-gray-800">{{ isPending ? 'Evaluación Pendiente' : 'Evaluación Completada' }}</h2>
                    <p class="text-[13px] text-gray-600 leading-snug mt-1">{{ isPending ? 'Asignada el' : 'Evaluada el' }} {{ formatDate(isPending ? evaluation.created_at : evaluation.updated_at) }}</p>
                </div>
            </div>

            <section class="bg-white p-4 sm:p-6 rounded-xl shadow-sm border border-gray-100">
                <h2 class="text-lg font-bold text-gray-900 border-b border-gray-100 pb-4 mb-6">Información General</h2>
                <dl class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Docente</dt><dd class="font-medium text-gray-900">{{ teacher || 'No registrado' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Institución</dt><dd class="font-medium text-gray-900">{{ applicant.institution || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Convocatoria</dt><dd class="font-medium text-gray-900">{{ applicant.announcement || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Área de Procedencia</dt><dd class="font-medium text-gray-900">{{ applicant.priority_area || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Subárea</dt><dd class="font-medium text-gray-900">{{ applicant.sub_area || 'No registrada' }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Fecha de Solicitud</dt><dd class="font-medium text-gray-900">{{ formatDate(applicant.application_date) }}</dd></div>
                    <div><dt class="text-xs uppercase text-gray-500 font-semibold mb-1">Tipo de Plaza</dt><dd class="font-medium text-gray-900">{{ applicant.position_type || 'No especificado' }}</dd></div>
                    <div>
                        <dt class="text-xs uppercase text-gray-500 font-semibold mb-2">Vía de Solicitud</dt>
                        <dd>
                            <span v-if="['larga', 'corta'].includes(applicant.via)" class="inline-flex px-4 py-2.5 rounded-lg text-xs font-bold uppercase tracking-wider text-white shadow-md" :class="applicant.via === 'larga' ? 'bg-[var(--institutional-primary)]' : 'bg-green-700'">{{ applicant.via === 'larga' ? 'Vía Larga' : 'Vía Corta' }}</span>
                            <span v-else class="text-gray-600">No especificada</span>
                        </dd>
                    </div>
                </dl>
            </section>

            <DocumentPreviewCards :documents="documents" route-name="national-evaluations.document" :owner-id="evaluation.id" />

            <form @submit.prevent="submit" class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
                <section class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="bg-[var(--institutional-primary)] px-6 py-4 border-b border-gray-200 flex flex-wrap justify-between items-center gap-3 text-white">
                        <div>
                            <h2 class="font-bold text-lg">{{ isPending ? 'Rúbrica de Evaluación' : 'Resultados de Evaluación' }}</h2>
                            <p class="text-blue-100 text-sm">{{ canEdit ? 'Selecciona una opción para cada criterio' : rubric?.title }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs opacity-80 uppercase tracking-widest">Puntuación Total</p>
                            <p class="text-3xl font-bold">{{ isPending ? selectedScore : evaluation.score }} <span class="text-lg font-normal">pts</span></p>
                        </div>
                    </div>

                    <div class="p-4 sm:p-6 space-y-8">
                        <p v-if="!rubric" class="text-center py-8 text-gray-500">No hay una rúbrica asignada.</p>

                        <fieldset v-for="(question, index) in questions" :key="question.id" :disabled="!canEdit" class="min-w-0 border-b border-gray-100 pb-6 last:border-0 last:pb-0">
                            <legend class="mb-3">
                                <span class="text-xs font-bold text-blue-600 uppercase tracking-wider mb-1 block">Criterio {{ index + 1 }}</span>
                                <span class="text-lg font-medium text-gray-900">{{ question.text }}</span>
                            </legend>
                            <div class="space-y-3">
                                <label
                                    v-for="option in question.options"
                                    :key="option.id"
                                    class="flex items-center gap-3 p-4 rounded-lg border transition-all duration-200"
                                    :class="[
                                        isSelected(question, option) ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500' : 'border-gray-200',
                                        canEdit && 'cursor-pointer hover:bg-gray-50',
                                    ]"
                                >
                                    <input type="radio" :name="'question-' + question.id" v-model="form.answers[question.id]" :value="option.id" required class="h-4 w-4 shrink-0 border-gray-300 text-blue-600 focus:ring-blue-500 disabled:opacity-50" />
                                    <span class="flex flex-1 flex-wrap items-center justify-between gap-2 min-w-0">
                                        <span class="text-sm font-medium text-gray-900" :class="{ 'text-blue-900': isSelected(question, option) }">{{ option.text }}</span>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="isSelected(question, option) ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'">{{ option.score }} pts</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    </div>
                </section>

                <aside class="bg-white rounded-xl shadow-md border border-gray-200 p-4 sm:p-6 lg:sticky lg:top-6">
                    <h2 class="text-lg font-bold text-gray-900 mb-4">{{ isPending ? 'Progreso de Evaluación' : 'Resumen de Evaluación' }}</h2>
                    <div v-if="isPending && rubric" class="space-y-3 mb-6">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-600">Criterios evaluados</span>
                            <span class="font-bold text-gray-900">{{ selectedOptions.length }} / {{ questions.length }}</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5" aria-hidden="true">
                            <div class="bg-[var(--institutional-primary)] h-2.5 rounded-full transition-all duration-500" :style="{ width: progressPercentage + '%' }"></div>
                        </div>
                    </div>

                    <label for="evaluation-comment" class="block mb-2 text-sm font-medium text-gray-900">Observaciones</label>
                    <textarea id="evaluation-comment" v-model="form.comment" :disabled="!canEdit" maxlength="1000" rows="4" placeholder="Escribe tus observaciones sobre la evaluación..." class="w-full rounded-lg border border-gray-300 bg-gray-50 p-3 focus:border-[var(--institutional-primary)] focus:ring-[var(--institutional-primary)] disabled:opacity-100" />

                    <p v-for="(error, field) in form.errors" :key="field" class="mt-2 text-sm text-red-600">{{ error }}</p>

                    <div class="flex flex-col gap-3 border-t border-gray-200 pt-5 mt-5">
                        <template v-if="canEdit">
                            <Link :href="route('national-evaluations.index')" class="w-full text-center px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 transition">Cancelar</Link>
                            <button type="submit" :disabled="confirming || form.processing || !rubric" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg shadow-sm text-sm font-medium text-white bg-[var(--institutional-primary)] hover:bg-[var(--institutional-primary-dark)] focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[var(--institutional-primary)] disabled:opacity-50 uppercase tracking-wide transition-colors">
                                <Save class="w-5 h-5 shrink-0" aria-hidden="true" />
                                {{ form.processing ? 'Guardando...' : 'Guardar evaluación nacional' }}
                            </button>
                        </template>
                        <p v-else-if="!isPending" class="text-center py-4 px-3 rounded-lg text-sm font-medium border text-green-700 bg-green-50 border-green-200">Evaluación finalizada. Puntaje: {{ evaluation.score }}</p>
                        <p v-else class="text-center py-4 px-3 rounded-lg text-sm font-medium border text-gray-700 bg-gray-50 border-gray-200">
                            {{ !evaluationPeriodOpen ? 'La convocatoria está fuera del periodo de evaluación nacional.' : 'La revisión nacional ha concluido.' }}
                        </p>
                    </div>
                </aside>
            </form>
        </div>
    </EvaluatorLayout>
</template>
