<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import SpendDayRow from '@/components/admin/SpendDayRow.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { spend } from '@/routes/admin/reports';
import type { SpendReport } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Spend report', href: spend() }],
    },
});

defineProps<{
    report: SpendReport;
}>();
</script>

<template>
    <Head title="Spend report" />

    <div class="mx-auto w-full max-w-6xl space-y-8 p-4">
        <Heading
            title="Spend report"
            description="What users were charged, what Pixelforge charged us, and the profit, per UTC day (the day Pixelforge completed and billed each generation)."
        />

        <Form
            v-bind="spend.form()"
            class="flex flex-wrap items-end gap-3"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-1">
                <Label for="start">From (UTC)</Label>
                <Input
                    id="start"
                    name="start"
                    type="date"
                    :default-value="report.start"
                />
            </div>
            <div class="grid gap-1">
                <Label for="end">To (UTC)</Label>
                <Input
                    id="end"
                    name="end"
                    type="date"
                    :default-value="report.end"
                />
            </div>
            <Button type="submit" variant="outline" :disabled="processing"
                >Show</Button
            >
            <InputError :message="errors.end ?? errors.start" class="w-full" />
        </Form>

        <dl class="grid gap-4 sm:grid-cols-4">
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">Charged to users</dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.total.charged.formatted }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">
                    Pixelforge cost (ours)
                </dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.total.cost.formatted }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">
                    Pixelforge cost (their /v1/usage)
                </dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.pixelforge.total?.formatted ?? 'Unavailable' }}
                </dd>
            </div>
            <div class="rounded-lg border p-4">
                <dt class="text-xs text-muted-foreground">Profit</dt>
                <dd class="font-semibold tabular-nums">
                    {{ report.total.profit.formatted }}
                </dd>
            </div>
        </dl>

        <p class="text-sm" role="status">
            <Badge v-if="!report.pixelforge.available" variant="outline">
                Pixelforge's usage report is unavailable right now
            </Badge>
            <Badge
                v-else-if="report.pixelforge.mismatched_days === 0"
                variant="secondary"
            >
                Every day matches Pixelforge's records exactly
            </Badge>
            <Badge v-else variant="destructive">
                {{ report.pixelforge.mismatched_days }} day(s) differ from
                Pixelforge's records
            </Badge>
        </p>

        <section class="space-y-2" aria-labelledby="days-heading">
            <h2 id="days-heading" class="font-medium">By day</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="py-2 font-normal">
                                Day (UTC)
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Generations
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Charged
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Cost (ours)
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Cost (Pixelforge)
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <SpendDayRow
                            v-for="day in report.days"
                            :key="day.date"
                            :day="day"
                        />
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-2" aria-labelledby="users-heading">
            <h2 id="users-heading" class="font-medium">
                By user (whole range)
            </h2>
            <p
                v-if="report.users.length === 0"
                class="text-sm text-muted-foreground"
            >
                No succeeded generations in this range.
            </p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" class="py-2 font-normal">User</th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Generations
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Charged
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Cost
                            </th>
                            <th scope="col" class="py-2 text-right font-normal">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in report.users"
                            :key="user.id"
                            class="border-t"
                        >
                            <td class="py-2">
                                {{ user.name }}
                                <span class="text-muted-foreground"
                                    >· {{ user.email }}</span
                                >
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                {{ user.generations }}
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                {{ user.charged.formatted }}
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                {{ user.cost.formatted }}
                            </td>
                            <td class="py-2 text-right tabular-nums">
                                {{ user.profit.formatted }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
