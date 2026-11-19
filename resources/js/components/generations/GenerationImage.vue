<script setup lang="ts">
import { ImageOff, LoaderCircle, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Generation } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const broken = ref(false);

const aspectRatio = computed(() => props.generation.size.replace('x', ' / '));

const expiresAt = computed(() => {
    const iso = props.generation.image?.expires_at;

    return iso ? new Date(iso).toLocaleString() : null;
});

const state = computed(() => {
    const { status, image } = props.generation;

    if (status === 'failed') {
        return 'failed';
    }

    if (!props.generation.is_finished) {
        return 'working';
    }

    return image?.url && !broken.value ? 'image' : 'expired';
});
</script>

<template>
    <figure class="w-full shrink-0 space-y-1 sm:w-40">
        <a
            v-if="state === 'image' && generation.image?.url"
            :href="generation.image.url"
            target="_blank"
            rel="noopener noreferrer"
            class="block overflow-hidden rounded-md border"
            :style="{ aspectRatio }"
        >
            <img
                :src="generation.image.url"
                :alt="`Generated image: ${generation.prompt}`"
                loading="lazy"
                referrerpolicy="no-referrer"
                class="size-full object-cover"
                @error="broken = true"
            />
        </a>

        <div
            v-else
            class="flex flex-col items-center justify-center gap-1 rounded-md border bg-muted/40 p-2 text-center text-xs text-muted-foreground"
            :style="{ aspectRatio }"
        >
            <template v-if="state === 'working'">
                <LoaderCircle class="size-5 animate-spin" aria-hidden="true" />
                {{ generation.status_label }}…
            </template>
            <template v-else-if="state === 'failed'">
                <TriangleAlert class="size-5" aria-hidden="true" />
                No image
            </template>
            <template v-else>
                <ImageOff class="size-5" aria-hidden="true" />
                Image expired (Pixelforge keeps images for 24 hours)
            </template>
        </div>

        <figcaption
            v-if="state === 'image' && expiresAt"
            class="text-xs text-muted-foreground"
        >
            Available until {{ expiresAt }}
        </figcaption>
    </figure>
</template>
