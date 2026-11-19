<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PriorityArea, SubArea, ReportFilters } from '@/types';

const props = withDefaults(defineProps<{
    modelValue?: ReportFilters;
    priorityAreas?: PriorityArea[];
    subAreas?: SubArea[];
    national?: boolean;
}>(), {
    modelValue: () => ({}),
    priorityAreas: () => [],
    subAreas: () => [],
});
const emit = defineEmits(['update:modelValue', 'change']);
const page = usePage();
const availableSubAreas = computed(() =>
    props.subAreas.filter(
        (item) =>
            !props.modelValue.priority_area_id ||
            String(item.priority_area_id) ===
                String(props.modelValue.priority_area_id),
    ),
);
const errors = computed(() =>
    Object.entries(page.props.errors || {}).filter(([key]) =>
        [
            'date_from',
            'date_to',
            'priority_area_id',
            'sub_area_id',
            'status',
        ].includes(key),
    ),
);
const update = (key: keyof ReportFilters, event: Event) => {
    const value = (event.target as HTMLInputElement | HTMLSelectElement).value;
    const next = { ...props.modelValue, [key]: value || null };
    if (key === 'priority_area_id') next.sub_area_id = null;
    emit('update:modelValue', next);
    emit('change');
};
const inputClass =
    'w-full rounded-lg border border-gray-300 bg-white p-2.5 text-sm text-gray-800';
</script>

<template>
    <fieldset class="mt-4 rounded-lg border border-gray-200 bg-white p-4">
        <legend class="px-2 text-sm font-semibold text-gray-700">
            Periodo de envío y clasificación de solicitudes
        </legend>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <label class="block text-sm text-gray-700"
                >Desde
                <input
                    type="date"
                    :class="inputClass"
                    :value="modelValue.date_from || ''"
                    :max="modelValue.date_to || undefined"
                    @change="update('date_from', $event)"
                />
            </label>
            <label class="block text-sm text-gray-700"
                >Hasta (incluido)
                <input
                    type="date"
                    :class="inputClass"
                    :value="modelValue.date_to || ''"
                    :min="modelValue.date_from || undefined"
                    @change="update('date_to', $event)"
                />
            </label>
            <label class="block text-sm text-gray-700"
                >Área
                <select
                    :class="inputClass"
                    :value="modelValue.priority_area_id || ''"
                    @change="update('priority_area_id', $event)"
                >
                    <option value="">Todas las áreas</option>
                    <option
                        v-for="item in priorityAreas"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option>
                </select>
            </label>
            <label class="block text-sm text-gray-700"
                >Subárea
                <select
                    :class="inputClass"
                    :value="modelValue.sub_area_id || ''"
                    @change="update('sub_area_id', $event)"
                >
                    <option value="">Todas las subáreas</option>
                    <option
                        v-for="item in availableSubAreas"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option>
                </select>
            </label>
            <label class="block text-sm text-gray-700"
                >Estatus {{ national ? 'nacional' : 'local' }}
                <select
                    :class="inputClass"
                    :value="modelValue.status || ''"
                    @change="update('status', $event)"
                >
                    <option value="">Aprobadas y rechazadas</option>
                    <option value="pending">Pendiente</option>
                    <option v-if="!national" value="in_review">
                        En revisión
                    </option>
                    <option value="approved">Aprobada</option>
                    <option value="rejected">Rechazada</option>
                </select>
            </label>
        </div>
        <p
            v-for="[key, message] in errors"
            :key="key"
            class="mt-1 text-sm text-red-700"
            role="alert"
        >
            {{ message }}
        </p>
    </fieldset>
</template>
