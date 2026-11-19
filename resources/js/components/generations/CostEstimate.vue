<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatRange, formatTokenRange } from '@/lib/money';
import { index as credits } from '@/routes/credits';
import type { GenerationQuote } from '@/types';

defineProps<{
    estimate: GenerationQuote | null;
    loading: boolean;
    invalid: boolean;
}>();
</script>

<template>
    <section
        class="space-y-3 rounded-lg border p-4"
        aria-live="polite"
        aria-labelledby="estimate-heading"
    >
        <div class="flex items-baseline justify-between gap-2">
            <h2 id="estimate-heading" class="text-sm font-medium">
                Estimated cost
            </h2>
            <span v-if="loading" class="text-xs text-muted-foreground">
                Updating…
            </span>
        </div>

        <p v-if="invalid" class="text-sm text-muted-foreground">
            Fix the highlighted fields to see the price.
        </p>

        <template v-else-if="estimate">
            <p class="text-2xl font-semibold tabular-nums">
                {{ formatRange(estimate) }}
            </p>
            <p class="text-sm text-muted-foreground">
                The final price depends on how many reasoning tokens Pixelforge
                uses, and is always within this range. You are charged the
                actual cost once the image is ready.
                <strong class="font-medium text-foreground">
                    Failed generations are free.
                </strong>
            </p>

            <table class="w-full text-sm">
                <caption class="sr-only">
                    Price breakdown
                </caption>
                <thead class="text-left text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="py-1 font-normal">Part</th>
                        <th scope="col" class="py-1 text-right font-normal">
                            Tokens
                        </th>
                        <th scope="col" class="py-1 text-right font-normal">
                            Price
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="line in estimate.lines"
                        :key="line.type"
                        class="border-t"
                    >
                        <td class="py-1">{{ line.label }}</td>
                        <td class="py-1 text-right tabular-nums">
                            {{ formatTokenRange(line.tokens) }}
                        </td>
                        <td class="py-1 text-right tabular-nums">
                            {{ formatRange(line.cost) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <p
                class="border-t pt-2 text-sm"
                :class="
                    estimate.affordable
                        ? 'text-muted-foreground'
                        : 'font-medium text-red-600 dark:text-red-500'
                "
            >
                You have {{ estimate.available.formatted }} available.
                <template v-if="!estimate.affordable">
                    That's less than this generation could cost, so it can't
                    start yet.
                    <Link :href="credits()" class="underline">Buy credits</Link>
                </template>
                <template v-else>
                    The maximum is held back until the image is ready; you're
                    charged only the actual cost.
                </template>
            </p>
        </template>

        <p v-else class="text-sm text-muted-foreground">Calculating…</p>
    </section>
</template>
