import { useHttp } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed } from 'vue';
import { estimate as estimateRoute } from '@/routes/generations';
import type { GenerationInput, GenerationQuote } from '@/types';

/**
 * Live price range for the generation being composed (ADR-0004).
 * Asks the server on every (debounced) change; the browser never calculates prices.
 */
export function useCostEstimate(input: () => GenerationInput) {
    const http = useHttp<GenerationInput, GenerationQuote>(input());

    function refresh(): void {
        http.cancel();
        http.transform(() => input())
            .submit(estimateRoute())
            .catch(() => undefined); // validation errors land in http.errors
    }

    watchDebounced(input, refresh, {
        debounce: 300,
        deep: true,
        immediate: true,
    });

    return {
        estimate: computed(() => (http.hasErrors ? null : http.response)),
        loading: computed(() => http.processing),
        invalid: computed(() => http.hasErrors),
    };
}
