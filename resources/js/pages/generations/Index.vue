<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import LiveStatusNotice from '@/components/generations/LiveStatusNotice.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useLiveGenerations } from '@/composables/useLiveGenerations';
import { create, index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const props = defineProps<{
    generations: Paginated<Generation>;
}>();

const { mode, degraded } = useLiveGenerations(() => props.generations.data);
</script>

<template>
    <Head title="History" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Your generations"
                description="What you asked for, what it cost, and what happened."
            />
            <Button as-child>
                <Link :href="create()">New generation</Link>
            </Button>
        </div>

        <LiveStatusNotice :mode="mode" :degraded="degraded" />

        <p
            v-if="generations.data.length === 0"
            class="rounded-lg border p-8 text-center text-muted-foreground"
        >
            Nothing here yet.
            <Link :href="create()" class="underline"
                >Generate your first image.</Link
            >
        </p>

        <ul v-else class="grid gap-4">
            <li v-for="generation in generations.data" :key="generation.id">
                <GenerationCard :generation="generation" />
            </li>
        </ul>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between text-sm"
            aria-label="Pagination"
        >
            <Link
                v-if="generations.links.prev"
                :href="generations.links.prev"
                class="underline"
            >
                Newer
            </Link>
            <span v-else />
            <span class="text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Link
                v-if="generations.links.next"
                :href="generations.links.next"
                class="underline"
            >
                Older
            </Link>
            <span v-else />
        </nav>
    </div>
</template>
