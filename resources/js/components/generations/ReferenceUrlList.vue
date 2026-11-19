<script setup lang="ts">
import { Plus } from '@lucide/vue';
import ReferenceUrlRow from '@/components/generations/ReferenceUrlRow.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const urls = defineModel<string[]>({ required: true });

defineProps<{
    max: number;
    errors: Partial<Record<string, string>>;
}>();

function add(): void {
    urls.value = [...urls.value, ''];
}

function remove(index: number): void {
    urls.value = urls.value.filter((_, i) => i !== index);
}
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="text-sm font-medium">
            Reference images
            <span class="font-normal text-muted-foreground">(optional)</span>
        </legend>
        <p class="text-sm text-muted-foreground">
            Public image URLs Pixelforge can use for style. Each one adds a
            fixed amount to the price. Up to {{ max }}.
        </p>

        <ReferenceUrlRow
            v-for="(_, index) in urls"
            :key="index"
            v-model="urls[index]"
            :position="index + 1"
            :error="errors[`reference_image_urls.${index}`]"
            @remove="remove(index)"
        />

        <InputError :message="errors.reference_image_urls" />

        <Button
            v-if="urls.length < max"
            type="button"
            variant="outline"
            size="sm"
            class="w-fit"
            @click="add"
        >
            <Plus /> Add reference image
        </Button>
    </fieldset>
</template>
