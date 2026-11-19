<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

const value = defineModel<string>({ required: true });

defineProps<{
    id: string;
    label: string;
    options: string[];
    error?: string;
}>();
</script>

<template>
    <div class="grid gap-2">
        <Label :for="id">{{ label }}</Label>
        <select
            :id="id"
            v-model="value"
            :aria-invalid="!!error"
            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-base capitalize shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
        >
            <option v-for="option in options" :key="option" :value="option">
                {{ option }}
            </option>
        </select>
        <InputError :message="error" />
    </div>
</template>
