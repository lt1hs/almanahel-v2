/** Exchange-rate helpers: market quote is “X toman per 1000 dinar”. */

export const DINAR_BASE = 1000;
export const DEFAULT_TOMAN_PER_1000_DINAR = 120_000;

export function parsePositiveRate(raw: unknown): number {
    const n = typeof raw === "number" ? raw : parseFloat(String(raw ?? ""));
    return Number.isFinite(n) && n > 0 ? n : 0;
}

/**
 * Convert toman → dinar using toman-per-1000-dinar rate.
 * Example: 120000 toman / 120000 rate → 1000 dinar.
 */
export function tomanToDinar(toman: number, tomanPer1000Dinar: number): number {
    if (!Number.isFinite(toman) || toman <= 0) return 0;
    const rate = parsePositiveRate(tomanPer1000Dinar);
    if (rate <= 0) return 0;
    return Math.round((toman * DINAR_BASE) / rate);
}

/**
 * Convert dinar → toman using toman-per-1000-dinar rate.
 * Example: 1000 dinar * 120000 / 1000 → 120000 toman.
 */
export function dinarToToman(dinar: number, tomanPer1000Dinar: number): number {
    if (!Number.isFinite(dinar) || dinar <= 0) return 0;
    const rate = parsePositiveRate(tomanPer1000Dinar);
    if (rate <= 0) return 0;
    return Math.round((dinar * rate) / DINAR_BASE);
}
