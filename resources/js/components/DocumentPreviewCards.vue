<script setup>
import { ref, watch, onUnmounted } from 'vue';
import axios from 'axios';
import { mdiFileDocumentMultiple } from '@mdi/js';
const props = defineProps({ documents: { type: Array, default: () => [] }, routeName: { type: String, required: true }, ownerId: { type: Number, required: true } });
const getFileIcon = (type) => {
    return 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5-3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z'; 
};
const togglePreview = (document) => {
    if (previewOpen.value && previewDocument.value?.id === document.id) previewOpen.value = false;
    else openPreview(document);
};
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
        const response = await axios.get(route(props.routeName, [props.ownerId, document.id]), { responseType: 'blob', signal: request.signal });
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
</script>

<template>
<div class="bg-white p-4 sm:p-6 rounded-xl shadow-sm border border-gray-100">
                    <h2 class="text-lg font-bold text-gray-900 mb-6 border-b border-gray-100 pb-2">Documentación</h2>

                    <div class="grid grid-cols-1 gap-4">
                        <div v-if="documents.length === 0" class="text-gray-500 italic text-center py-4 bg-gray-50 rounded-lg">
                            No hay documentos adjuntos.
                        </div>

                        <div v-for="document in documents" :key="document.id"
                            class="border border-gray-200 rounded-xl p-5 hover:bg-gray-50 transition shadow-sm"
                            :class="{ 'bg-blue-50/50 ring-1 ring-[var(--institutional-primary)]/10': (previewOpen && previewDocument?.id === document.id) }"
                        >
                            <div class="flex flex-col sm:flex-row items-center justify-between gap-6">
                                <div class="flex items-center gap-4 w-full sm:w-auto min-w-0">
                                    <div class="flex-shrink-0 w-12 h-12 flex items-center justify-center bg-gray-100 rounded-xl text-[var(--institutional-primary)]"
                                         :class="{ 'bg-blue-100': (previewOpen && previewDocument?.id === document.id) }">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                                            <path stroke-linecap="round" stroke-linejoin="round" :d="getFileIcon(document.file_type)" />
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <span class="font-bold text-gray-900 block text-base md:text-lg truncate" :title="document.name">{{ document.name }}</span>
                                        <p class="text-xs text-gray-500 font-medium uppercase tracking-wider mt-0.5">Documento PDF</p>
                                    </div>
                                </div>

                                <!-- Actions -->
                                <div class="flex items-center gap-2 w-full sm:w-auto justify-end flex-shrink-0">
                                    <button type="button"
                                        
                                        @click="togglePreview(document)"
                                        :aria-expanded="previewOpen && previewDocument?.id === document.id"
                                        class="inline-flex items-center justify-center gap-2 px-4 py-2 border rounded-lg font-bold transition cursor-pointer text-xs uppercase whitespace-nowrap shadow-sm"
                                        :class="(previewOpen && previewDocument?.id === document.id)
                                            ? 'bg-[var(--institutional-primary)] text-white border-[var(--institutional-primary)]'
                                            : 'text-[var(--institutional-primary)] border-[var(--institutional-primary)] hover:bg-[var(--institutional-primary)] hover:text-white'"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor">
                                            <path v-if="!(previewOpen && previewDocument?.id === document.id)" d="M480-320q75 0 127.5-52.5T660-500q0-75-52.5-127.5T480-680q-75 0-127.5 52.5T300-500q0 75 52.5 127.5T480-320Zm0-72q-45 0-76.5-31.5T372-500q0-45 31.5-76.5T480-608q45 0 76.5 31.5T588-500q0 45-31.5 76.5T480-392Zm0 192q-146 0-266-81.5T40-500q54-137 174-218.5T480-800q146 0 266 81.5T920-500q-54 137-174 218.5T480-200Z"/>
                                            <path v-else d="m644-428-58-58q9-47-27-88t-93-32l-58-58q17-8 34.5-12t37.5-4q75 0 127.5 52.5T660-500q0 20-4 37.5T644-428Zm128 126-58-56q38-29 67.5-63.5T832-500q-50-101-143.5-160.5T480-720q-29 0-57 4t-55 12l-62-62q41-17 84-25.5t90-8.5q151 0 269 83.5T920-500q-23 59-60.5 109.5T772-302Zm20 246L624-222q-35 11-70.5 16.5T480-200q-151 0-269-83.5T40-500q21-53 53-98.5t73-81.5L56-792l56-56 736 736-56 56ZM222-624q-29 26-53 57t-41 67q50 101 143.5 160.5T480-280q20 0 39-2.5t39-5.5l-36-38q-11 3-21 4.5t-21 1.5q-75 0-127.5-52.5T300-500q0-11 1.5-21t4.5-21l-84-82Zm319 93Zm-151 75Z"/>
                                        </svg>
                                        {{ (previewOpen && previewDocument?.id === document.id) ? 'Ocultar' : 'Visualizar' }}
                                    </button>


                                </div>
                            </div>

                            <!-- Inline Preview (Premium) -->
                            <div v-if="(previewOpen && previewDocument?.id === document.id)" class="mt-6 pt-6 border-t border-gray-200 w-full animate-fadeIn">
                                <div class="flex flex-col gap-4">
                                    <div class="flex justify-between items-center">
                                        <h3 class="font-bold text-gray-800 text-lg flex items-center gap-2">
                                            <svg viewBox="0 0 24 24" class="w-5 h-5 flex-shrink-0" style="fill: #1B396A;">
                                                <path :d="mdiFileDocumentMultiple"/>
                                            </svg>
                                            Vista Previa: {{ document.name }}
                                        </h3>
                                        <button type="button" @click="togglePreview(document)" class="text-gray-400 hover:text-gray-600 p-1.5 hover:bg-gray-100 rounded-lg transition cursor-pointer" title="Cerrar vista previa">
                                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="w-full h-[55vh] sm:h-[65vh] lg:h-[600px] bg-white rounded-xl overflow-hidden border border-gray-300 shadow-inner relative">
                                        <div v-if="previewLoading" role="status" class="absolute inset-0 flex items-center justify-center text-gray-400 z-0 text-center">
                                            <div class="flex flex-col items-center gap-2">
                                                <svg class="w-10 h-10 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                </svg>
                                                <span>Cargando vista previa...</span>
                                            </div>
                                        </div>
                                        <div v-if="previewError" role="alert" class="absolute inset-0 flex flex-col items-center justify-center gap-4 p-6 text-center"><p class="text-red-600">{{ previewError }}</p><button type="button" @click="openPreview(document)" class="rounded-lg border px-4 py-2">Reintentar</button></div>
                                        <iframe v-if="previewUrl" :title="`Vista previa de ${document.name}`" :src="previewUrl" class="w-full h-full relative z-10" frameborder="0"></iframe>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
</template>
