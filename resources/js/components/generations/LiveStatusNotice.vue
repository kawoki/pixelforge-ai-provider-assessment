<script setup lang="ts">
import type { LiveMode } from '@/types';

defineProps<{
    mode: LiveMode;
    degraded: boolean;
}>();
</script>

<template>
    <div
        v-if="degraded"
        role="status"
        class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100"
    >
        <strong class="font-medium"
            >Updates are running slower than usual.</strong
        >
        We're checking Pixelforge directly for your generations every few
        seconds while we fix it. Nothing is lost, and you're only charged for
        images that succeed.
    </div>

    <p
        v-else-if="mode === 'polling'"
        role="status"
        class="text-xs text-muted-foreground"
    >
        Live updates are unavailable right now, so this page refreshes every few
        seconds.
    </p>

    <p
        v-else-if="mode === 'live'"
        class="flex items-center gap-1.5 text-xs text-muted-foreground"
    >
        <span class="size-2 rounded-full bg-green-500" aria-hidden="true" />
        Live: statuses update by themselves.
    </p>
</template>
