<script setup lang="ts">
import { computed } from 'vue';
import GenerationImage from '@/components/generations/GenerationImage.vue';
import { Badge } from '@/components/ui/badge';
import { formatRange } from '@/lib/money';
import type { Generation, GenerationStatus } from '@/types';

const props = defineProps<{
    generation: Generation;
}>();

const badgeVariant: Record<
    GenerationStatus,
    'secondary' | 'outline' | 'default' | 'destructive'
> = {
    pending: 'outline',
    queued: 'outline',
    processing: 'secondary',
    succeeded: 'default',
    failed: 'destructive',
};

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(),
);

const charged = computed(() => {
    const { status, actual_cost } = props.generation;

    if (status === 'failed') {
        return 'Not charged';
    }

    return actual_cost?.formatted ?? 'When finished';
});
</script>

<template>
    <article class="flex flex-col gap-4 rounded-lg border p-4 sm:flex-row">
        <GenerationImage :generation="generation" />

        <div class="min-w-0 flex-1 space-y-2">
            <header class="flex items-start justify-between gap-4">
                <p class="line-clamp-2 font-medium break-words">
                    {{ generation.prompt }}
                </p>
                <Badge :variant="badgeVariant[generation.status]">
                    {{ generation.status_label }}
                </Badge>
            </header>

            <p class="text-xs text-muted-foreground">
                {{ generation.size }} ·
                <span class="capitalize">{{ generation.quality }}</span>
                <template v-if="generation.reference_image_count">
                    · {{ generation.reference_image_count }} reference image(s)
                </template>
                · <time :datetime="generation.created_at">{{ createdAt }}</time>
            </p>

            <dl class="grid grid-cols-2 gap-2 text-sm sm:max-w-md">
                <div>
                    <dt class="text-xs text-muted-foreground">Estimated</dt>
                    <dd class="tabular-nums">
                        {{ formatRange(generation.estimated_cost) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Charged</dt>
                    <dd class="tabular-nums">{{ charged }}</dd>
                </div>
            </dl>

            <p
                v-if="generation.failure_message"
                class="text-sm text-red-600 dark:text-red-500"
                role="status"
            >
                {{ generation.failure_message }}
            </p>
        </div>
    </article>
</template>
