/**
 * Money as the server sends it (ADR-0003): exact amount + display string.
 * Never do arithmetic on these; show `formatted`.
 */
export type Money = {
    amount: string;
    formatted: string;
};

export type MoneyRange = {
    min: Money;
    max: Money;
};

export type GenerationOptions = {
    sizes: string[];
    qualities: string[];
    default_size: string;
    default_quality: string;
    max_reference_images: number;
    prompt_max_length: number;
};

export type GenerationInput = {
    prompt: string;
    size: string;
    quality: string;
    reference_image_urls: string[];
    /** Only ever set by the server, as validation-style errors. */
    credits?: never;
    limit?: never;
};

export type CostEstimateLine = {
    type: string;
    label: string;
    tokens: { min: number; max: number };
    cost: MoneyRange;
};

export type CostEstimate = MoneyRange & {
    lines: CostEstimateLine[];
};

/** The estimate plus what this user can spend; `affordable` is decided by the server. */
export type GenerationQuote = CostEstimate & {
    available: Money;
    affordable: boolean;
    /** Set when a per-user fairness limit blocks starting another generation now. */
    limit_reason: string | null;
};

export type BalanceSummary = {
    balance: Money;
    /** Held back for generations still running (their estimate maximum). */
    reserved: Money;
    available: Money;
};

export type CreditPack = {
    id: string;
    label: string;
    amount: Money;
};

export type CreditTransaction = {
    id: number;
    type: 'purchase' | 'charge' | 'adjustment';
    label: string;
    amount: Money;
    balance_after: Money;
    generation: { id: string; prompt: string } | null;
    created_at: string;
};

export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_image_count: number;
    status: GenerationStatus;
    status_label: string;
    is_finished: boolean;
    failure_message: string | null;
    /** Null until succeeded. `url` is null once Pixelforge's 24 h link has expired. */
    image: GenerationImage | null;
    estimated_cost: MoneyRange;
    actual_cost: Money | null;
    created_at: string;
    completed_at: string | null;
};

export type GenerationImage = {
    url: string | null;
    expires_at: string | null;
    expired: boolean;
};

/**
 * How the History page learns about changes (ADR-0009, ADR-0015):
 * - idle:     nothing in flight; no polling
 * - live:     pushed over WebSockets; a slow backstop poll just in case
 * - polling:  WebSockets unavailable; poll every few seconds
 * - fallback: background processing is down; each poll also asks the server to check Pixelforge
 */
export type LiveMode = 'idle' | 'live' | 'polling' | 'fallback';

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
};

/** Admin spend report (ADR-0012). Money has 9 decimals, like Pixelforge's `cost_usd`. */
export type SpendFigures = {
    generations: number;
    charged: Money;
    cost: Money;
    profit: Money;
};

export type SpendUserRow = SpendFigures & {
    id: number;
    name: string;
    email: string;
};

export type SpendDay = SpendFigures & {
    date: string;
    pixelforge: { generations: number; cost: Money } | null;
    /** null when Pixelforge's numbers are unavailable */
    matches: boolean | null;
    users: SpendUserRow[];
};

export type SpendReport = {
    start: string;
    end: string;
    total: SpendFigures;
    pixelforge: {
        available: boolean;
        total: Money | null;
        mismatched_days: number;
    };
    days: SpendDay[];
    users: SpendUserRow[];
};

/** Admin System status page (ADR-0015). */
export type SystemHealth = {
    degraded: boolean;
    scheduler: { running: boolean; last_beat: string | null };
    queue: { running: boolean; last_beat: string | null };
};
