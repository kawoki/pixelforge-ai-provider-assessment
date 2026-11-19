/**
 * Live updates need a Reverb app key at build time. Without one (e.g. no WebSocket server in
 * this environment), Echo is never started and pages fall back to polling (ADR-0009).
 */
export const liveUpdatesConfigured = Boolean(
    import.meta.env.VITE_REVERB_APP_KEY,
);
