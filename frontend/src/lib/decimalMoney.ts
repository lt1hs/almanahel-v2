/** Normalize API decimal strings to two fractional digits without float conversion. */
export function normalizeDecimalString(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === "") {
        return "0.00";
    }
    const raw = String(value).trim();
    if (!/^-?\d+(\.\d+)?$/.test(raw)) {
        return "0.00";
    }
    const negative = raw.startsWith("-");
    const unsigned = negative ? raw.slice(1) : raw;
    const [intPart, frac = ""] = unsigned.split(".");
    const frac2 = (frac + "00").slice(0, 2);
    return `${negative ? "-" : ""}${intPart}.${frac2}`;
}

export function isPositiveDecimalString(value: string): boolean {
    const normalized = normalizeDecimalString(value);
    if (normalized.startsWith("-")) {
        return false;
    }
    return normalized !== "0.00";
}

/** Sum decimal strings using integer cents — never JavaScript floats. */
export function addDecimalStrings(...values: string[]): string {
    let cents = BigInt(0);
    for (const value of values) {
        const normalized = normalizeDecimalString(value);
        const negative = normalized.startsWith("-");
        const unsigned = negative ? normalized.slice(1) : normalized;
        const [intPart, frac = "00"] = unsigned.split(".");
        const fracPadded = (frac + "00").slice(0, 2);
        const partCents = BigInt(intPart) * BigInt(100) + BigInt(fracPadded);
        cents += negative ? -partCents : partCents;
    }
    const hundred = BigInt(100);
    const negative = cents < BigInt(0);
    if (negative) {
        cents = -cents;
    }
    const whole = cents / hundred;
    const frac = (cents % hundred).toString().padStart(2, "0");
    return `${negative ? "-" : ""}${whole}.${frac}`;
}

/** Display-only conversion; never use for mutation payloads. */
export function decimalStringToDisplayNumber(value: string): number {
    return Number(normalizeDecimalString(value));
}
