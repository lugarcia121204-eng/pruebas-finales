<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch, onUnmounted } from 'vue';
import axios from 'axios';
import { ArrowLeft, Eye, FileText } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import DialogModal from '@/components/DialogModal.vue';
import { AlertTriangle, X } from 'lucide-vue-next';
import VueSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { alertaConfirmacionEscrita, alertaExito } from '@/utils/alerts.js';
const props = defineProps({ review: Object, evaluators: Array, evaluationPeriodOpen: Boolean });
const previewOpen = ref(false);
const previewDocument = ref(null);
const previewUrl = ref('');
const previewLoading = ref(false);
const previewError = ref('');
let previewRequest;
const clearPreview = () => {
    previewRequest?.abort();
    previewRequest = null;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = '';
    previewLoading.value = false;
};
const openPreview = async (document) => {
    clearPreview();
    previewDocument.value = document;
    previewError.value = '';
    previewOpen.value = true;
    previewLoading.value = true;
    const request = new AbortController();
    previewRequest = request;
    try {
        const response = await axios.get(route('national-review.document', [props.review.id, document.id]), { responseType: 'blob', signal: request.signal });
        if (request.signal.aborted) return;
        if (!(await response.data.slice(0, 5).text()).startsWith('%PDF-')) throw new Error('invalid-pdf');
        if (request.signal.aborted) return;
        previewUrl.value = URL.createObjectURL(new Blob([response.data], { type: 'application/pdf' }));
    } catch (error) {
        if (!request.signal.aborted) previewError.value = 'No se pudo cargar el documento. Verifica que el archivo esté disponible e inténtalo de nuevo.';
    } finally {
        if (previewRequest === request) previewLoading.value = false;
    }
};
watch(previewOpen, (open) => { if (!open) clearPreview(); });
onUnmounted(clearPreview);
const formatDate = (value) => value ? new Date(value).toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Mexico_City' }) : 'No registrada';
const formatEvaluationScore = (evaluation) => {
    if (evaluation.status !== 'completed' || evaluation.score === null || evaluation.score === undefined || evaluation.score === '') return '—';
    const score = Number(evaluation.score);
    return Number.isFinite(score) ? score.toLocaleString('es-MX', { maximumFractionDigits: 2 }) : '—';
};
const assignment = useForm({ evaluator_ids: [] });
const closing = ref(false);
const decision = useForm({ status: '', comment: '' });
const confirmingDecision = ref(false);
const approveReview = () => {
    decision.clearErrors();
    decision.status = 'approved';
    decision.comment = '';
    finishReview();
};
const rejectReview = () => {
    decision.clearErrors();
    decision.status = 'rejected';
    closing.value = true;
};
const finishReview = async () => {
    if (decision.status === 'rejected' && !decision.comment.trim()) {
        decision.setError('comment', 'El motivo del rechazo es obligatorio.');
        return;
    }
    if (!decision.status || confirmingDecision.value || decision.processing) return;

    confirmingDecision.value = true;
    closing.value = false;
    await nextTick();

    try {
        const approved = decision.status === 'approved';
        const confirmed = await alertaConfirmacionEscrita(
            approved ? '¿Aprobar solicitud en etapa nacional?' : '¿Rechazar solicitud en etapa nacional?',
            approved
                ? 'Esta acción aprobará la solicitud en la etapa nacional. Esta operación no se puede deshacer.'
                : 'Esta acción rechazará la solicitud en la etapa nacional. Esta operación no se puede deshacer.',
            'CONFIRMAR',
        );
        if (!confirmed) {
            closing.value = !approved;
            return;
        }
        decision.post(route('national-review.verdict', props.review.id), {
            preserveScroll: false,
            onSuccess: () => alertaExito('Revisión finalizada', 'El resultado nacional se guardó correctamente.'),
            onError: () => { closing.value = !approved; },
        });
    } finally {
        confirmingDecision.value = false;
    }
};
const assign = () => assignment.post(route('national-review.assign-evaluators', props.review.id), { preserveScroll: true, onSuccess: () => { assignment.reset(); alertaExito('Asignados', 'Los evaluadores recibirán una nueva evaluación nacional.'); } });
</script>

<template>
    <DynamicLayout>
        <Head title="Revisión nacional" />
        <div class="space-y-6">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <h1 class="text-2xl font-bold">Revisión nacional</h1>
                <Link :href="route('national-review.index')" class="inline-flex items-center justify-center gap-2 self-end rounded-lg border border-gray-300 px-5 py-3 font-medium text-gray-700 transition hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--institutional-primary)]">
                    <ArrowLeft class="h-5 w-5" aria-hidden="true" />
                    Volver a solicitudes nacionales
                </Link>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-6 sm:p-8 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 pb-5 mb-7">
                    <h2 class="text-xl font-bold text-gray-900">Información de la Solicitud</h2>
                    <span class="rounded-lg px-3 py-2 text-xs font-semibold shadow-sm" :class="{ 'bg-amber-50 text-amber-800': review.status === 'pending', 'bg-green-50 text-green-800': review.status === 'approved', 'bg-red-50 text-red-800': review.status === 'rejected' }">{{ { pending: 'Pendiente de revisión nacional', approved: 'Aprobada en etapa nacional', rejected: 'Rechazada en etapa nacional' }[review.status] }}</span>
                </div>
                <dl class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-x-8 gap-y-7">
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Profesor</dt><dd class="text-base font-medium text-gray-900">{{ review.teacher || 'No registrado' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Área Prioritaria</dt><dd class="text-base text-gray-900">{{ review.priority_area || 'No registrada' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Institución</dt><dd class="text-base text-gray-900">{{ review.institution || 'No registrada' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Tipo de Beca</dt><dd class="text-base text-gray-900">{{ review.announcement || 'No registrado' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Tipo de Plaza</dt><dd class="text-base text-gray-900">{{ review.position_type || 'No especificado' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-2">Vía de Solicitud</dt><dd><span v-if="['larga', 'corta'].includes(review.via)" class="inline-flex rounded-lg px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-sm bg-[var(--institutional-primary)]">{{ review.via === 'larga' ? 'Modalidad productividad (Evaluación Docente)' : 'Diferenciado (Promoción Docente)' }}</span><span v-else class="text-gray-600">No especificada</span></dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Fecha de Solicitud</dt><dd class="text-base text-gray-900">{{ formatDate(review.application_date) }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Correo Electrónico</dt><dd class="text-base text-gray-900 break-words">{{ review.teacher_email || 'No registrado' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">CURP</dt><dd class="text-base text-gray-900">{{ review.teacher_curp || 'No registrada' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Subárea</dt><dd class="text-base text-gray-900">{{ review.sub_area || 'No registrada' }}</dd></div>
                    <div><dt class="text-sm font-medium text-gray-600 mb-1">Estado</dt><dd class="text-base text-gray-900">{{ review.state || 'No registrado' }}</dd></div>
                </dl>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-5 sm:p-8 shadow-sm">
                <h2 class="text-xl font-bold text-gray-900 border-b border-gray-100 pb-4 mb-6">Documentación</h2>
                <ul class="space-y-4">
                    <li v-for="document in review.documents" :key="document.id" class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 rounded-xl border border-gray-200 p-5 sm:p-6 shadow-sm even:bg-gray-50">
                        <div class="flex items-center gap-4 min-w-0">
                            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-[var(--institutional-primary)]"><FileText class="h-7 w-7" aria-hidden="true" /></span>
                            <div class="min-w-0"><h3 class="text-lg font-bold text-gray-900 break-words">{{ document.name }}</h3><p class="mt-1 text-xs uppercase tracking-wide text-gray-500">Documento PDF</p></div>
                        </div>
                        <button type="button" @click="openPreview(document)" :aria-label="`Visualizar ${document.name}`" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-[var(--institutional-primary)] px-4 py-2.5 text-xs font-bold uppercase text-[var(--institutional-primary)] shadow-sm hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--institutional-primary)]"><Eye class="h-4 w-4" aria-hidden="true" />Visualizar</button>
                    </li>
                </ul>
                <p v-if="!review.documents.length" class="py-6 text-center text-gray-500">No hay documentos disponibles.</p>
            </div>
            <Dialog v-model:open="previewOpen">
                <DialogContent class="w-[calc(100%-2rem)] sm:max-w-6xl h-[90dvh] flex flex-col gap-3 p-4 sm:p-6">
                    <DialogTitle class="pr-7 text-lg font-bold">{{ previewDocument?.name }}</DialogTitle>
                    <DialogDescription>Vista previa del documento del expediente digital.</DialogDescription>
                    <div class="flex-1 min-h-0 rounded-lg border border-gray-200 bg-gray-100 overflow-hidden">
                        <div v-if="previewLoading" role="status" class="h-full flex items-center justify-center text-gray-600">Cargando documento...</div>
                        <div v-else-if="previewError" role="alert" class="h-full flex flex-col items-center justify-center gap-4 p-6 text-center"><p class="text-red-600">{{ previewError }}</p><button type="button" @click="openPreview(previewDocument)" class="rounded-lg border px-4 py-2">Reintentar</button></div>
                        <iframe v-else-if="previewUrl" :src="previewUrl" :title="`Vista previa de ${previewDocument?.name}`" class="h-full w-full border-0" />
                    </div>
                    <div class="flex justify-end"><button type="button" @click="previewOpen = false" class="rounded-lg border border-gray-300 px-5 py-2 font-medium">Cerrar</button></div>
                </DialogContent>
            </Dialog>
            <div v-if="review.status === 'pending'" class="rounded-lg border bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Evaluaciones nacionales</h2>
                <p v-if="review.status === 'pending' && !evaluationPeriodOpen" class="text-sm text-amber-800">La convocatoria está fuera del periodo de evaluación nacional.</p>
                <form v-if="review.status === 'pending' && evaluationPeriodOpen" @submit.prevent="assign" class="space-y-3">
                    <label for="national-evaluators" class="block font-medium">Asignar evaluadores</label>
                    <VueSelect input-id="national-evaluators" v-model="assignment.evaluator_ids" :options="evaluators.filter(user => !review.evaluations.some(evaluation => evaluation.evaluator_id === user.id))" :reduce="user => user.id" label="label" multiple placeholder="Seleccionar evaluadores..." class="national-evaluators-select">
                        <template #no-options>No hay evaluadores disponibles.</template>
                    </VueSelect>
                    <p v-if="assignment.errors.evaluator_ids" class="text-red-600">{{ assignment.errors.evaluator_ids }}</p>
                    <button :disabled="assignment.processing || !assignment.evaluator_ids.length" class="rounded-lg bg-[var(--institutional-primary)] px-4 py-2 text-white disabled:opacity-50">Asignar evaluadores</button>
                </form>
            </div>
            <section class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-6 py-4"><h2 class="text-lg font-bold text-gray-900">Evaluadores Asignados</h2></div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[920px] text-left">
                        <thead class="bg-[var(--institutional-primary)] text-white">
                            <tr><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase w-12">ID</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase">Nombre</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase">Comentarios</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase text-right whitespace-nowrap">Puntaje de rúbrica</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase text-center">Estado</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase text-center">Acciones</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(evaluation, index) in review.evaluations" :key="evaluation.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm text-gray-500">{{ index + 1 }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ evaluation.evaluator }}</td>
                                <td class="max-w-xs break-words whitespace-pre-line px-6 py-4 text-sm"><span v-if="evaluation.comment" class="text-gray-800">{{ evaluation.comment }}</span><span v-else class="italic text-gray-400">Sin comentarios</span></td>
                                <td class="px-6 py-4 text-right text-sm font-semibold text-gray-900">{{ formatEvaluationScore(evaluation) }}</td>
                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 rounded-md border border-gray-100 bg-white px-2.5 py-1 text-xs font-bold shadow-sm" :class="evaluation.status === 'completed' ? 'text-green-700' : 'text-yellow-700'"><span class="h-2 w-2 rounded-full" :class="evaluation.status === 'completed' ? 'bg-green-500' : 'bg-yellow-500'"></span>{{ evaluation.status === 'completed' ? 'Completada' : 'Pendiente' }}</span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <Link v-if="evaluation.status === 'completed'" :href="route('national-review.responses', [review.id, evaluation.id])" class="inline-flex items-center gap-2 rounded-lg border border-[var(--institutional-primary)] px-3 py-2 text-xs font-bold text-[var(--institutional-primary)] shadow-sm hover:bg-blue-50">
                                        <Eye class="h-4 w-4" aria-hidden="true" />Respuestas
                                    </Link>
                                    <button v-else type="button" disabled class="inline-flex items-center gap-2 rounded-lg border border-[var(--institutional-primary)] px-3 py-2 text-xs font-bold text-[var(--institutional-primary)] shadow-sm cursor-not-allowed opacity-40">
                                        <Eye class="h-4 w-4" aria-hidden="true" />Respuestas
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!review.evaluations.length"><td colspan="6" class="px-6 py-8 text-center text-gray-500">Aún no se han asignado evaluadores para el segundo filtro.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <div v-if="review.status === 'approved'" class="rounded-lg border border-gray-200 bg-gray-50 p-4 text-center">
                <p class="font-medium text-gray-600">
                    Esta solicitud ya ha sido completada con estado: <strong class="font-bold text-green-600">Aprobado en Etapa Nacional</strong>
                </p>
            </div>
            <div class="space-y-4">
                <div v-if="evaluationPeriodOpen && review.status === 'pending' && review.evaluations.length && review.evaluations.every(evaluation => evaluation.status === 'completed')" class="border-t border-gray-200 pt-4 flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm text-gray-600">Todas las evaluaciones están completas. Puedes finalizar la revisión nacional.</p>
                    <div class="w-full flex flex-col sm:flex-row gap-4">
                        <button type="button" @click="approveReview" :disabled="confirmingDecision || decision.processing" class="flex-1 bg-[var(--institutional-primary)] text-white py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-wider hover:bg-[var(--institutional-primary-dark)] transition shadow-md cursor-pointer transform hover:-translate-y-0.5 disabled:opacity-50">Aprobar</button>
                        <button type="button" @click="rejectReview" :disabled="confirmingDecision || decision.processing" class="flex-1 bg-[#d32f2f] text-white py-2.5 rounded-lg text-[11px] font-bold uppercase tracking-wider hover:bg-[#b71c1c] transition shadow-md cursor-pointer transform hover:-translate-y-0.5 disabled:opacity-50">Rechazar</button>
                        <Link :href="route('national-review.index')" class="w-full sm:w-auto px-6 py-2.5 bg-white border border-[var(--institutional-primary)] rounded-lg text-[11px] font-bold uppercase tracking-wider text-[var(--institutional-primary)] hover:bg-gray-50 transition shadow-sm text-center cursor-pointer">Cancelar</Link>
                    </div>
                    <p v-if="decision.errors.status" class="text-sm text-red-600">{{ decision.errors.status }}</p>
                </div>
            </div>
            <DialogModal :show="closing" @close="closing = false" maxWidth="lg">
                <template #title>
                    <div class="flex items-center justify-between py-2 border-b border-gray-100">
                        <div class="flex items-center gap-3">
                            <AlertTriangle class="h-6 w-6 text-red-600" aria-hidden="true" />
                            <span class="font-bold text-lg text-gray-800 uppercase tracking-tight">Rechazar solicitud nacional</span>
                        </div>
                        <button type="button" @click="closing = false" aria-label="Cerrar" class="text-gray-400 hover:text-gray-600 transition-colors cursor-pointer"><X class="h-6 w-6" /></button>
                    </div>
                </template>
                <template #content>
                    <form id="national-rejection-form" @submit.prevent="finishReview" class="space-y-4 pt-4">
                        <div class="relative flex items-center gap-4 px-5 py-4 rounded-lg bg-white shadow-sm border border-gray-100 border-l-[5px] border-l-red-500">
                            <div class="flex flex-col">
                                <span class="text-[10px] uppercase font-bold tracking-widest text-red-500 opacity-80 mb-0.5">Instrucciones</span>
                                <span class="text-sm font-bold leading-tight text-gray-800">Proporciona una justificación detallada para rechazar la solicitud en la etapa nacional.</span>
                            </div>
                        </div>
                        <div>
                            <label for="final-national-comment" class="block mb-2 text-base text-gray-900 font-medium uppercase tracking-tight">Motivo del rechazo: <span class="text-red-500">*</span></label>
                            <textarea id="final-national-comment" v-model="decision.comment" required maxlength="1000" class="bg-[#F3F4F6] border-t-0 border-x-0 text-gray-900 text-sm rounded-lg focus:ring-0 block w-full p-2.5 border-b-2 border-b-gray-300 focus:border-b-[var(--institutional-primary)] transition-all min-h-[160px] leading-relaxed" placeholder="Escribe aquí los motivos detallados..." />
                            <div class="flex justify-between items-center mt-1"><span class="text-xs text-gray-500">Escribe una descripción</span><span class="text-gray-400 text-sm">{{ decision.comment.length }}/1000</span></div>
                            <p v-for="(error, key) in decision.errors" :key="key" class="mt-1 text-sm text-red-600">{{ error }}</p>
                        </div>
                    </form>
                </template>
                <template #footer>
                    <div class="flex items-center gap-3 w-full sm:w-auto">
                        <button type="button" @click="closing = false" class="flex-1 sm:flex-none px-6 py-2.5 rounded-lg border border-gray-300 bg-white text-xs font-bold uppercase tracking-widest hover:bg-gray-200 transition cursor-pointer">Cancelar</button>
                        <button type="submit" form="national-rejection-form" :disabled="confirmingDecision || decision.processing" class="flex-1 sm:flex-none px-8 py-2.5 bg-red-600 text-white rounded-lg text-xs font-bold uppercase tracking-widest hover:bg-red-700 transition shadow-lg shadow-red-200/50 disabled:opacity-50 cursor-pointer">Confirmar rechazo</button>
                    </div>
                </template>
            </DialogModal>

        </div>
    </DynamicLayout>
</template>

<style scoped>
.national-evaluators-select :deep(.vs__dropdown-toggle) {
    min-height: 52px;
    padding: 7px 12px;
    border-radius: 0.5rem;
}
.national-evaluators-select :deep(.vs__selected-options) {
    align-items: center;
    min-width: 0;
}
.national-evaluators-select :deep(.vs__search),
.national-evaluators-select :deep(.vs__selected) {
    font-size: 1rem;
}
.national-evaluators-select :deep(.vs__search) {
    margin-top: 0;
    padding: 4px 0;
}
</style>
