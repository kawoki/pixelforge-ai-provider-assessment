<script setup lang="ts">
import { ref } from 'vue';
import type { SpendDay } from '@/types';

defineProps<{
    day: SpendDay;
}>();

const open = ref(false);
</script>

<template>
    <tr
        class="border-t"
        :class="{ 'bg-red-50 dark:bg-red-950/40': day.matches === false }"
    >
        <td class="py-2 whitespace-nowrap">
            <button
                v-if="day.users.length"
                type="button"
                class="underline decoration-dotted"
                :aria-expanded="open"
                @click="open = !open"
            >
                {{ day.date }}
            </button>
            <span v-else>{{ day.date }}</span>
        </td>
        <td class="py-2 text-right tabular-nums">{{ day.generations }}</td>
        <td class="py-2 text-right tabular-nums">
            {{ day.charged.formatted }}
        </td>
        <td class="py-2 text-right tabular-nums">{{ day.cost.formatted }}</td>
        <td class="py-2 text-right tabular-nums">
            <template v-if="day.pixelforge">
                {{ day.pixelforge.cost.formatted }}
                <span
                    :title="
                        day.matches
                            ? 'Matches Pixelforge'
                            : 'Differs from Pixelforge'
                    "
                    :aria-label="day.matches ? 'matches' : 'differs'"
                >
                    {{ day.matches ? '✓' : '✗' }}
                </span>
            </template>
            <span v-else class="text-muted-foreground">n/a</span>
        </td>
        <td class="py-2 text-right tabular-nums">{{ day.profit.formatted }}</td>
    </tr>
    <tr
        v-for="user in open ? day.users : []"
        :key="`${day.date}-${user.id}`"
        class="text-muted-foreground"
    >
        <td class="py-1 pl-4">{{ user.name }} · {{ user.email }}</td>
        <td class="py-1 text-right tabular-nums">{{ user.generations }}</td>
        <td class="py-1 text-right tabular-nums">
            {{ user.charged.formatted }}
        </td>
        <td class="py-1 text-right tabular-nums">{{ user.cost.formatted }}</td>
        <td class="py-1" />
        <td class="py-1 text-right tabular-nums">
            {{ user.profit.formatted }}
        </td>
    </tr>
</template>
