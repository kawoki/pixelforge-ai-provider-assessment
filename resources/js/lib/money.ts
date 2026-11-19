import type { MoneyRange } from '@/types';

/**
 * "$0.0581 – $0.0863", or a single amount when the range is exact.
 * Pure display: compares the server's formatted strings, never amounts.
 */
export function formatRange(range: MoneyRange): string {
    return range.min.formatted === range.max.formatted
        ? range.min.formatted
        : `${range.min.formatted} – ${range.max.formatted}`;
}

/**
 * "1,100" or "200 – 2,000" tokens.
 */
export function formatTokenRange(tokens: { min: number; max: number }): string {
    const format = (n: number) => n.toLocaleString();

    return tokens.min === tokens.max
        ? format(tokens.min)
        : `${format(tokens.min)} – ${format(tokens.max)}`;
}
