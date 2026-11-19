<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { index, purchase } from '@/routes/credits';
import type {
    BalanceSummary,
    CreditPack,
    CreditTransaction,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

defineProps<{
    summary: BalanceSummary;
    transactions: Paginated<CreditTransaction>;
    packs: CreditPack[];
    purchaseKey: string;
}>();

const formatDate = (iso: string) => new Date(iso).toLocaleString();
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto w-full max-w-5xl space-y-8 p-4">
        <Heading
            title="Credits"
            description="Credits are US dollars. Each generation is charged its actual cost once the image is ready."
        />

        <dl class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">Available</dt>
                <dd class="text-2xl font-semibold tabular-nums">
                    {{ summary.available.formatted }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">Balance</dt>
                <dd class="text-2xl tabular-nums">
                    {{ summary.balance.formatted }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">
                    Held for running generations
                </dt>
                <dd class="text-2xl tabular-nums">
                    {{ summary.reserved.formatted }}
                </dd>
            </div>
        </dl>

        <section class="space-y-3" aria-labelledby="buy-heading">
            <h2 id="buy-heading" class="font-medium">Buy credits</h2>
            <p class="text-sm text-muted-foreground">
                This is a demo: no payment is taken.
            </p>
            <div class="flex flex-wrap gap-3">
                <Form
                    v-for="pack in packs"
                    :key="pack.id"
                    v-bind="purchase.form()"
                    v-slot="{ processing }"
                >
                    <input type="hidden" name="pack" :value="pack.id" />
                    <input
                        type="hidden"
                        name="idempotency_key"
                        :value="purchaseKey"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                    >
                        {{ pack.label }}: {{ pack.amount.formatted }}
                    </Button>
                </Form>
            </div>
        </section>

        <section class="space-y-3" aria-labelledby="history-heading">
            <h2 id="history-heading" class="font-medium">History</h2>

            <p
                v-if="transactions.data.length === 0"
                class="text-sm text-muted-foreground"
            >
                No credit activity yet.
            </p>

            <table v-else class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr>
                        <th scope="col" class="py-2 font-normal">When</th>
                        <th scope="col" class="py-2 font-normal">What</th>
                        <th scope="col" class="py-2 text-right font-normal">
                            Amount
                        </th>
                        <th scope="col" class="py-2 text-right font-normal">
                            Balance after
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="transaction in transactions.data"
                        :key="transaction.id"
                        class="border-t"
                    >
                        <td class="py-2 whitespace-nowrap">
                            {{ formatDate(transaction.created_at) }}
                        </td>
                        <td class="max-w-xs truncate py-2">
                            {{ transaction.label }}
                            <span
                                v-if="transaction.generation"
                                class="text-muted-foreground"
                            >
                                · {{ transaction.generation.prompt }}
                            </span>
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ transaction.amount.formatted }}
                        </td>
                        <td class="py-2 text-right tabular-nums">
                            {{ transaction.balance_after.formatted }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <nav
                v-if="transactions.meta.last_page > 1"
                class="flex justify-between text-sm"
                aria-label="Pagination"
            >
                <Link
                    v-if="transactions.links.prev"
                    :href="transactions.links.prev"
                    class="underline"
                    >Newer</Link
                >
                <span v-else />
                <Link
                    v-if="transactions.links.next"
                    :href="transactions.links.next"
                    class="underline"
                    >Older</Link
                >
            </nav>
        </section>
    </div>
</template>
