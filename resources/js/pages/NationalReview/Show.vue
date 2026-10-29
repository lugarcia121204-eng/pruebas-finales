<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, watch, onUnmounted } from 'vue';
import axios from 'axios';
import { ArrowLeft, Eye, FileText } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
import VueSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { alertaExito } from '@/utils/alerts.js';
const props = defineProps({ review: Object, evaluators: Array });
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
const assignment = useForm({ evaluator_ids: [] });
const closing = ref(false);
const selectedEvaluation = ref(null);
const responsesOpen = ref(false);
const showResponses = (evaluation) => { selectedEvaluation.value = evaluation; responsesOpen.value = true; };
const decision = useForm({ status: '', comment: '' });
const finishReview = () => decision.post(route('national-review.verdict', props.review.id), {
    preserveScroll: true,
    onSuccess: () => { closing.value = false; alertaExito('Revisión finalizada', 'El resultado nacional se guardó correctamente.'); },
});
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
                    <div><dt class="text-sm font-medium text-gray-600 mb-2">Vía de Solicitud</dt><dd><span v-if="['larga', 'corta'].includes(review.via)" class="inline-flex rounded-lg px-4 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-sm" :class="review.via === 'larga' ? 'bg-[var(--institutional-primary)]' : 'bg-green-700'">{{ review.via === 'larga' ? 'Vía larga' : 'Vía corta' }}</span><span v-else class="text-gray-600">No especificada</span></dd></div>
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
            <div class="rounded-lg border bg-white p-6 space-y-4">
                <h2 class="text-lg font-semibold">Evaluaciones nacionales</h2>
                <form v-if="review.status === 'pending'" @submit.prevent="assign" class="space-y-3">
                    <label for="national-evaluators" class="block font-medium">Asignar evaluadores</label>
                    <VueSelect input-id="national-evaluators" v-model="assignment.evaluator_ids" :options="evaluators.filter(user => !review.evaluations.some(evaluation => evaluation.evaluator_id === user.id))" :reduce="user => user.id" label="label" multiple placeholder="Seleccionar evaluadores...">
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
                            <tr><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase w-12">ID</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase">Nombre</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase">Comentarios</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase text-center">Estado</th><th scope="col" class="px-6 py-3 text-xs font-semibold uppercase text-center">Acciones</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(evaluation, index) in review.evaluations" :key="evaluation.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 text-sm text-gray-500">{{ index + 1 }}</td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ evaluation.evaluator }}</td>
                                <td class="max-w-xs break-words whitespace-pre-line px-6 py-4 text-sm"><span v-if="evaluation.comment" class="text-gray-800">{{ evaluation.comment }}</span><span v-else class="italic text-gray-400">Sin comentarios</span></td>
                                <td class="px-6 py-4 text-center whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5 rounded-md border border-gray-100 bg-white px-2.5 py-1 text-xs font-bold shadow-sm" :class="evaluation.status === 'completed' ? 'text-green-700' : 'text-yellow-700'"><span class="h-2 w-2 rounded-full" :class="evaluation.status === 'completed' ? 'bg-green-500' : 'bg-yellow-500'"></span>{{ evaluation.status === 'completed' ? 'Completada' : 'Pendiente' }}</span>
                                    <p v-if="evaluation.status === 'completed'" class="mt-1 text-xs text-gray-500">Puntaje: {{ evaluation.score }}</p>
                                </td>
                                <td class="px-6 py-4 text-center"><button type="button" @click="showResponses(evaluation)" :disabled="evaluation.status !== 'completed'" class="inline-flex items-center gap-2 rounded-lg border border-[var(--institutional-primary)] px-3 py-2 text-xs font-bold text-[var(--institutional-primary)] shadow-sm hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-40"><Eye class="h-4 w-4" aria-hidden="true" />Respuestas</button></td>
                            </tr>
                            <tr v-if="!review.evaluations.length"><td colspan="5" class="px-6 py-8 text-center text-gray-500">A?n no se han asignado evaluadores para el segundo filtro.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <div class="space-y-4">
                <div v-if="review.status === 'pending' && review.evaluations.length && review.evaluations.every(evaluation => evaluation.status === 'completed')" class="border-t border-gray-200 pt-4 flex flex-wrap items-center justify-between gap-4">
                    <p class="text-sm text-gray-600">Todas las evaluaciones están completas. Puedes finalizar la revisión nacional.</p>
                    <button type="button" @click="closing = true" class="rounded-lg bg-[var(--institutional-primary)] px-5 py-2.5 font-medium text-white">Finalizar revisión</button>
                </div>
            </div>
            <Dialog v-model:open="responsesOpen">
                <DialogContent class="sm:max-w-2xl max-h-[85dvh] overflow-y-auto">
                    <DialogTitle>Respuestas de la evaluaci?n nacional</DialogTitle>
                    <DialogDescription>{{ selectedEvaluation?.evaluator }} ? Puntaje: {{ selectedEvaluation?.score }}</DialogDescription>
                    <div v-for="response in selectedEvaluation?.responses || []" :key="response.id" class="rounded-lg border border-gray-200 p-4 space-y-2">
                        <h3 class="font-semibold text-gray-900">{{ response.question }}</h3>
                        <p class="text-gray-700">{{ response.answer || 'Respuesta no disponible' }}<span v-if="response.answer"> ({{ response.score }} puntos)</span></p>
                    </div>
                    <p v-if="!selectedEvaluation?.responses?.length" class="text-gray-500">No hay respuestas disponibles.</p>
                    <div><h3 class="font-semibold">Observaciones</h3><p class="mt-1 whitespace-pre-line break-words text-gray-600">{{ selectedEvaluation?.comment || 'Sin comentarios' }}</p></div>
                    <div class="flex justify-end"><button type="button" @click="responsesOpen = false" class="rounded-lg border border-gray-300 px-4 py-2">Cerrar</button></div>
                </DialogContent>
            </Dialog>
            <Dialog v-model:open="closing">
                <DialogContent>
                    <DialogTitle>Finalizar revisión nacional</DialogTitle>
                    <DialogDescription>Confirma el resultado del segundo filtro. Esta decisión determina la emisión del reconocimiento.</DialogDescription>
                    <form @submit.prevent="finishReview" class="space-y-4">
                        <label for="final-national-result" class="block font-medium">Resultado</label>
                        <select id="final-national-result" v-model="decision.status" required class="w-full rounded-lg border border-gray-300 p-3"><option disabled value="">Selecciona un resultado</option><option value="approved">Aprobar</option><option value="rejected">Rechazar</option></select>
                        <label for="final-national-comment" class="block font-medium">Motivo <span v-if="decision.status === 'rejected'">(obligatorio)</span></label>
                        <textarea id="final-national-comment" v-model="decision.comment" :required="decision.status === 'rejected'" maxlength="1000" rows="3" class="w-full rounded-lg border border-gray-300 p-3" />
                        <p v-for="(error, key) in decision.errors" :key="key" class="text-sm text-red-600">{{ error }}</p>
                        <div class="flex justify-end gap-3"><button type="button" @click="closing = false" class="rounded-lg border px-4 py-2">Cancelar</button><button :disabled="decision.processing" class="rounded-lg bg-[var(--institutional-primary)] px-4 py-2 text-white disabled:opacity-50">Confirmar resultado</button></div>
                    </form>
                </DialogContent>
            </Dialog>

        </div>
    </DynamicLayout>
</template>
