<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { system } from '@/routes/admin';
import type { SystemHealth } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'System status', href: system() }],
    },
});

defineProps<{
    health: SystemHealth;
    counts: {
        waiting_jobs: number;
        failed_jobs: number;
        unprocessed_webhooks: number;
    };
}>();

const when = (iso: string | null) =>
    iso ? new Date(iso).toLocaleString() : 'never';
</script>

<template>
    <Head title="System status" />

    <div class="mx-auto w-full max-w-6xl space-y-8 p-4">
        <Heading
            title="System status"
            description="Background processes and what is waiting to be processed. Alerts are emailed to operators."
        />

        <section
            class="grid gap-4 sm:grid-cols-2"
            aria-label="Background processes"
        >
            <div
                v-for="(process, name) in {
                    Scheduler: health.scheduler,
                    'Queue worker': health.queue,
                }"
                :key="name"
                class="rounded-lg border p-4"
            >
                <div class="flex items-center justify-between">
                    <h2 class="font-medium">{{ name }}</h2>
                    <Badge
                        :variant="process.running ? 'secondary' : 'destructive'"
                    >
                        {{ process.running ? 'Running' : 'Stopped' }}
                    </Badge>
                </div>
                <p class="text-sm text-muted-foreground">
                    Last heartbeat: {{ when(process.last_beat) }}
                </p>
            </div>
        </section>

        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">Jobs waiting</dt>
                <dd class="text-xl tabular-nums">{{ counts.waiting_jobs }}</dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">Failed jobs</dt>
                <dd class="text-xl tabular-nums">{{ counts.failed_jobs }}</dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">
                    Webhooks not yet processed
                </dt>
                <dd class="text-xl tabular-nums">
                    {{ counts.unprocessed_webhooks }}
                </dd>
            </div>
        </dl>
    </div>
</template>
