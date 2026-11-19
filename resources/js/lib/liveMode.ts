import type { LiveMode } from '@/types';

/** Milliseconds between polls per mode; null means don't poll. */
export const POLL_INTERVALS: Record<LiveMode, number | null> = {
    idle: null,
    live: 30_000, // backstop only: pushes do the real work
    polling: 4_000,
    fallback: 5_000,
};

/**
 * Pick how to keep the page current. Degraded background processing wins: then polling
 * is the only thing that moves generations forward at all.
 */
export function resolveLiveMode(state: {
    inFlight: boolean;
    degraded: boolean;
    connected: boolean;
}): LiveMode {
    if (!state.inFlight) {
        return 'idle';
    }

    if (state.degraded) {
        return 'fallback';
    }

    return state.connected ? 'live' : 'polling';
}
