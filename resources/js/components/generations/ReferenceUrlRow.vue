<script setup lang="ts">
import { X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const url = defineModel<string>({ required: true });

const props = defineProps<{
    position: number;
    error?: string;
}>();

const emit = defineEmits<{ remove: [] }>();

const id = `reference-${props.position}`;
</script>

<template>
    <div class="grid gap-1">
        <div class="flex gap-2">
            <Label :for="id" class="sr-only">
                Reference image {{ position }}
            </Label>
            <Input
                :id="id"
                v-model="url"
                type="url"
                inputmode="url"
                placeholder="https://example.com/style.png"
                :aria-invalid="!!error"
            />
            <Button
                type="button"
                variant="outline"
                size="icon"
                :aria-label="`Remove reference image ${position}`"
                @click="emit('remove')"
            >
                <X />
            </Button>
        </div>
        <InputError :message="error" />
    </div>
</template>
