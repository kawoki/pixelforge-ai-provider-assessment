import { router, useHttp, usePage } from '@inertiajs/vue3';
import type { ConnectionStatus } from '@laravel/echo-vue';
import { useConnectionStatus, useEcho } from '@laravel/echo-vue';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';
import { liveUpdatesConfigured } from '@/lib/echo';
import { POLL_INTERVALS, resolveLiveMode } from '@/lib/liveMode';
import { catchUp as catchUpRoute } from '@/routes/generations';
import type { Generation, LiveMode } from '@/types';

/**
 * Keeps a list of generations current (ADR-0009, ADR-0015): pushed over WebSockets when
 * possible, polled when not, and, while background processing is down, polled with a
 * request that makes the server check Pixelforge itself.
 */
export function useLiveGenerations(generations: () => Generation[]) {
    const page = usePage();
    const connection = useLiveConnection(
        `App.Models.User.${page.props.auth.user.id}`,
        refresh,
    );
    const degraded = computed(() => page.props.background?.degraded ?? false);

    const mode = computed<LiveMode>(() =>
        resolveLiveMode({
            inFlight: generations().some(
                (generation) => !generation.is_finished,
            ),
            degraded: degraded.value,
            connected: connection.value === 'connected',
        }),
    );

    usePolling(mode, useTick(mode));

    return { mode, degraded };
}

function refresh(): void {
    router.reload({ only: ['generations', 'background'] });
}

/**
 * Subscribe to the user's private channel, or report "failed" when WebSockets aren't configured.
 */
function useLiveConnection(
    channel: string,
    onUpdate: () => void,
): Ref<ConnectionStatus> {
    if (!liveUpdatesConfigured) {
        return ref<ConnectionStatus>('failed');
    }

    useEcho(channel, '.generation.updated', onUpdate);

    return useConnectionStatus();
}

function useTick(mode: ComputedRef<LiveMode>): () => void {
    const catchUp = useHttp({});

    return () => {
        if (mode.value !== 'fallback') {
            return refresh();
        }

        catchUp
            .submit(catchUpRoute(), { onFinish: refresh })
            .catch(() => undefined);
    };
}

function usePolling(mode: ComputedRef<LiveMode>, tick: () => void): void {
    const interval = ref(POLL_INTERVALS.polling ?? 4_000);
    const { pause, resume } = useIntervalFn(tick, interval, {
        immediate: false,
    });

    watch(
        mode,
        (current) => {
            const ms = POLL_INTERVALS[current];
            pause();

            if (ms !== null) {
                interval.value = ms;
                resume();
            }
        },
        { immediate: true },
    );
}
