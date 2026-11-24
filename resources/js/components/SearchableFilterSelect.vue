<script setup lang="ts">
import { computed } from 'vue';
import VueSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';

interface FilterOption { id: string | number; name: string; }
const props = defineProps<{
    modelValue?: string | number | null;
    options: FilterOption[];
    emptyLabel: string;
    inputId?: string;
}>();
const emit = defineEmits(['update:modelValue', 'change']);
const options = computed(() => [
    { id: '', name: props.emptyLabel },
    ...props.options.map(option => ({ ...option, id: String(option.id) })),
]);
const selected = computed({
    get: () => String(props.modelValue ?? ''),
    set: (value: string | null) => {
        emit('update:modelValue', value ?? '');
        emit('change');
    },
});
const reduce = (option: { id: string }) => option.id;
const normalize = (value: string) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');
const filterBy = (_option: unknown, label: string, search: string) => normalize(label).includes(normalize(search));
</script>

<template>
    <VueSelect v-model="selected" :options="options" :reduce="reduce" label="name" :input-id="inputId" :aria-label="emptyLabel" :placeholder="emptyLabel" :searchable="true" :filter-by="filterBy" append-to-body class="searchable-filter-select">
        <template #no-options>No se encontraron resultados.</template>
    </VueSelect>
</template>

<style scoped>
.searchable-filter-select :deep(.vs__dropdown-toggle) {
    min-height: 42px;
    padding: 5px 8px;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    background: #fff;
}
.searchable-filter-select :deep(.vs__selected-options) { min-width: 0; }
.searchable-filter-select :deep(.vs__selected),
.searchable-filter-select :deep(.vs__search) {
    margin: 0;
    font-size: 0.875rem;
    color: #111827;
}
.searchable-filter-select :deep(.vs__selected) {
    max-width: 100%;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.searchable-filter-select :deep(.vs__dropdown-toggle:focus-within) {
    border-color: var(--institutional-primary);
    box-shadow: 0 0 0 2px rgb(27 57 106 / 12%);
}
</style>
