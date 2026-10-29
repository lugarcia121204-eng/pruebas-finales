<script setup>
import { Head } from '@inertiajs/vue3';
import { ref, nextTick, onMounted, onUnmounted } from 'vue';
import axios from 'axios';
import { MessageCircle, Send, Search, Pencil, Trash2 } from 'lucide-vue-next';
import DynamicLayout from '@/layouts/DynamicLayout.vue';
const props = defineProps({ contacts: Array, institution: String, isAdministrator: Boolean, selectedContactId: Number });
const selected = ref(null);
const messages = ref([]);
const body = ref('');
const sending = ref(false);
const editing = ref(null);
const editBody = ref('');
const deleting = ref(null);
const mutating = ref(false);
const loading = ref(false);
const error = ref('');
const search = ref('');
const viewport = ref(null);
const hasOlder = ref(false);
const olderLoading = ref(false);
const unread = ref(Object.fromEntries(props.contacts.map(contact => [contact.id, Number(contact.unread_count)])));
let timer;
let request;
let fetching = false;
const scrollBottom = async () => { await nextTick(); if (viewport.value) viewport.value.scrollTop = viewport.value.scrollHeight; };
const load = async (initial = false) => {
    if (!selected.value || fetching || mutating.value) return;
    const contact = selected.value.id;
    fetching = true;
    const controller = new AbortController();
    request = controller;
    if (initial) loading.value = true;
    try {
        const nearBottom = !viewport.value || viewport.value.scrollHeight - viewport.value.scrollTop - viewport.value.clientHeight < 100;
        const after = !initial && messages.value.length ? messages.value[messages.value.length - 1].id : undefined;
        const { data } = await axios.get(route('institution-chat.messages', contact), { params: { after, sync_from: !initial && messages.value.length ? messages.value[0].id : undefined, sync_through: after }, signal: controller.signal, timeout: 15000 });
        if (selected.value?.id !== contact || controller.signal.aborted) return;
        if (initial) { messages.value = data.messages; hasOlder.value = data.messages.length === 50; }
        else {
            if (data.synced) {
                const synced = new Map(data.synced.map(message => [message.id, message]));
                messages.value = messages.value.filter(message => message.id > after || synced.has(message.id)).map(message => synced.get(message.id) || message);
                if (editing.value && !messages.value.some(message => message.id === editing.value)) editing.value = null;
            }
            const ids = new Set(messages.value.map(message => message.id)); messages.value.push(...data.messages.filter(message => !ids.has(message.id)));
        }
        if (initial || nearBottom) await scrollBottom();
        if (messages.value.length && !document.hidden) {
            await axios.post(route('institution-chat.read', contact), { through: messages.value[messages.value.length - 1].id }, { signal: controller.signal, timeout: 15000 });
            unread.value[contact] = 0;
        }
        error.value = '';
    } catch (failure) {
        if (!controller.signal.aborted && selected.value?.id === contact) error.value = 'No se pudo actualizar la conversación. Se reintentará automáticamente.';
    } finally {
        if (request === controller) { fetching = false; loading.value = false; }
    }
};
const select = (contact) => {
    if (sending.value || olderLoading.value || mutating.value) return;
    request?.abort(); fetching = false;
    editing.value = null; deleting.value = null; editBody.value = '';
    selected.value = contact; messages.value = []; body.value = ''; error.value = ''; hasOlder.value = false;
    load(true);
};
const older = async () => {
    if (olderLoading.value || !selected.value || !messages.value.length) return;
    olderLoading.value = true;
    const contact = selected.value.id;
    const height = viewport.value?.scrollHeight || 0;
    try {
        const { data } = await axios.get(route('institution-chat.messages', contact), { params: { before: messages.value[0].id }, timeout: 15000 });
        if (selected.value?.id !== contact) return;
        messages.value.unshift(...data.messages);
        hasOlder.value = data.messages.length === 50;
        await nextTick();
        if (viewport.value) viewport.value.scrollTop += viewport.value.scrollHeight - height;
    } catch { error.value = 'No se pudieron cargar los mensajes anteriores.'; }
    finally { olderLoading.value = false; }
};
const send = async () => {
    if (!selected.value || !body.value.trim() || sending.value) return;
    sending.value = true; error.value = '';
    const contact = selected.value.id;
    try {
        await axios.post(route('institution-chat.send', contact), { body: body.value.trim() }, { timeout: 20000 });
        body.value = '';
        await load(); await scrollBottom();
    } catch (failure) { error.value = failure.response?.data?.errors?.body?.[0] || 'No se pudo enviar el mensaje. Intenta de nuevo.'; }
    finally { sending.value = false; }
};
const messageKeydown = event => {
    if (event.key !== 'Enter' || event.shiftKey || event.isComposing || event.keyCode === 229) return;
    event.preventDefault();
    if (!event.repeat) send();
};
const edit = message => { editing.value = message.id; editBody.value = message.body; deleting.value = null; };
const changeMessage = async (message, remove = false) => {
    if (mutating.value || !selected.value || (!remove && !editBody.value.trim())) return;
    request?.abort(); fetching = false;
    mutating.value = true; error.value = '';
    const contact = selected.value.id;
    try {
        const url = route(remove ? 'institution-chat.destroy' : 'institution-chat.update', { contact, message: message.id });
        if (remove) await axios.delete(url, { timeout: 15000 });
        else await axios.patch(url, { body: editBody.value.trim() }, { timeout: 15000 });
        if (remove) messages.value = messages.value.filter(item => item.id !== message.id);
        else message.body = editBody.value.trim();
        editing.value = null; deleting.value = null;
    } catch (failure) {
        error.value = failure.response?.data?.errors?.body?.[0] || 'No se pudo guardar el cambio. Intenta de nuevo.';
    } finally { mutating.value = false; }
};
const time = value => new Date(value).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
onMounted(() => {
    const initial = props.contacts.find(contact => contact.id === Number(props.selectedContactId));
    if (initial) select(initial);
    timer = setInterval(() => { if (!document.hidden) load(); }, 5000); });
onUnmounted(() => { clearInterval(timer); request?.abort(); });
</script>

<template>
    <DynamicLayout>
        <Head title="Chat de la institución" />
        <div class="space-y-5">
            <div><h1 class="text-3xl font-bold text-gray-900">Chat de la institución</h1><p class="mt-2 text-gray-600">{{ institution }} · {{ isAdministrator ? 'Comunicación con los docentes de tu sede' : 'Comunicación con la administración de tu sede' }}</p></div>
            <div class="grid overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm md:grid-cols-[280px_1fr]">
                <aside class="border-b border-gray-200 md:border-b-0 md:border-r">
                    <div class="border-b border-gray-100 p-4"><h2 class="mb-3 font-bold">{{ isAdministrator ? 'Docentes' : 'Administradores' }}</h2><div class="relative"><Search class="absolute left-3 top-3 h-4 w-4 text-gray-500" /><input v-model="search" aria-label="Buscar contacto" placeholder="Buscar contacto..." class="w-full rounded-lg border border-gray-300 py-2 pl-9 pr-3 text-sm" /></div></div>
                    <div class="max-h-48 overflow-y-auto md:max-h-[520px]">
                        <button v-for="contact in contacts.filter(contact => contact.name.toLocaleLowerCase().includes(search.toLocaleLowerCase()))" :key="contact.id" type="button" :disabled="sending || olderLoading || mutating" @click="select(contact)" class="flex w-full items-center justify-between gap-2 border-b border-gray-100 px-4 py-4 text-left text-sm hover:bg-blue-50 disabled:opacity-60" :class="selected?.id === contact.id ? 'bg-blue-50 text-[var(--institutional-primary)] font-semibold' : 'text-gray-800'"><span>{{ contact.name }}</span><span v-if="unread[contact.id]" class="rounded-full bg-[var(--institutional-primary)] px-2 py-0.5 text-xs text-white">{{ unread[contact.id] }}</span></button>
                        <p v-if="!contacts.length" class="p-5 text-sm text-gray-500">{{ isAdministrator ? 'No hay docentes registrados en tu sede.' : 'No hay administradores locales registrados en tu sede.' }}</p>
                    </div>
                </aside>
                <section v-if="selected" class="flex min-w-0 flex-col">
                    <header class="flex items-center gap-3 border-b border-gray-100 px-5 py-4"><MessageCircle class="h-5 w-5 text-[var(--institutional-primary)]" /><h2 class="font-bold">{{ selected.name }}</h2></header>
                    <div ref="viewport" class="flex h-[420px] flex-col gap-3 overflow-y-auto bg-gray-50 p-4 sm:p-5" aria-live="polite" aria-label="Mensajes de la conversación">
                        <button v-if="hasOlder" type="button" :disabled="olderLoading" @click="older" class="self-center text-sm font-medium text-[var(--institutional-primary)]">{{ olderLoading ? 'Cargando...' : 'Ver mensajes anteriores' }}</button>
                        <p v-if="loading" role="status" class="m-auto text-gray-500">Cargando conversación...</p>
                        <p v-else-if="!messages.length" class="m-auto text-center text-sm text-gray-500">Escribe el primer mensaje para iniciar la conversación.</p>
                        <div v-for="message in messages" :key="message.id" class="relative max-w-[calc(100%-5rem)] sm:max-w-[75%] rounded-xl px-4 py-3 shadow-sm" :class="message.mine ? 'self-end bg-[var(--institutional-primary)] text-white' : 'self-start border border-gray-200 bg-white text-gray-900'"><form v-if="editing === message.id" @submit.prevent="changeMessage(message)" class="space-y-2">
                            <textarea v-model="editBody" aria-label="Editar mensaje" maxlength="4000" rows="3" :disabled="mutating" class="w-full min-w-48 rounded border border-gray-300 bg-white p-2 text-sm text-gray-900" />
                            <div class="flex gap-3 text-xs"><button type="submit" :disabled="mutating || !editBody.trim()" class="font-semibold disabled:opacity-50">{{ mutating ? 'Guardando...' : 'Guardar' }}</button><button type="button" :disabled="mutating" @click="editing = null">Cancelar</button></div>
                        </form>
                        <p v-else class="whitespace-pre-wrap break-words text-sm">{{ message.body }}</p>
                        <div v-if="message.mine && editing !== message.id" class="mt-2 flex flex-wrap justify-end gap-3 text-xs">
                            <template v-if="deleting === message.id"><span>?Eliminar este mensaje para ambos?</span><button type="button" :disabled="mutating" @click="changeMessage(message, true)" class="font-semibold disabled:opacity-50">{{ mutating ? 'Eliminando...' : 'Eliminar' }}</button><button type="button" :disabled="mutating" @click="deleting = null">Cancelar</button></template>
                            <div v-else class="absolute right-full top-2 mr-1 flex flex-col items-center gap-0.5 whitespace-nowrap text-gray-500">
                                <button type="button" :disabled="mutating" @click="deleting = message.id; editing = null" aria-label="Eliminar mensaje" title="Eliminar mensaje" class="shrink-0 rounded p-1 transition hover:bg-red-100 hover:text-red-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600 disabled:opacity-50"><Trash2 class="h-3 w-3" /></button>
                                <button type="button" :disabled="mutating" @click="edit(message)" aria-label="Editar mensaje" title="Editar mensaje" class="shrink-0 rounded p-1 transition hover:bg-blue-100 hover:text-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600 disabled:opacity-50"><Pencil class="h-3 w-3" /></button>
                            </div>
                        </div><p class="mt-2 text-right text-[10px] opacity-70">{{ time(message.created_at) }}</p></div>
                    </div>
                    <p v-if="error" role="alert" class="px-4 py-2 text-sm text-red-600">{{ error }}</p>
                    <form @submit.prevent="send" class="flex items-end gap-3 border-t border-gray-100 p-4"><div class="flex-1"><label for="chat-message" class="sr-only">Mensaje</label><textarea id="chat-message" v-model="body" @keydown="messageKeydown" :disabled="sending" maxlength="4000" rows="2" placeholder="Escribe un mensaje... (Enter para enviar, Shift+Enter para nueva línea)" class="w-full resize-none rounded-lg border border-gray-300 p-3 text-sm" /><p class="mt-1 text-right text-xs text-gray-400">{{ body.length }}/4000</p></div><button :disabled="sending || !body.trim()" class="mb-5 inline-flex items-center gap-2 rounded-lg bg-[var(--institutional-primary)] px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"><Send class="h-4 w-4" />{{ sending ? 'Enviando...' : 'Enviar' }}</button></form>
                </section>
                <div v-else class="flex min-h-[350px] flex-col items-center justify-center gap-3 p-8 text-center text-gray-500"><MessageCircle class="h-12 w-12 text-gray-300" /><p>Selecciona {{ isAdministrator ? 'un docente' : 'un administrador' }} para abrir la conversación.</p></div>
            </div>
        </div>
    </DynamicLayout>
</template>
