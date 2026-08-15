export const BRANCH_STOCK_KEYS = ["warehouse", "qom", "mashhad", "najaf", "tehran"] as const;
export type BranchStockKey = (typeof BRANCH_STOCK_KEYS)[number];

export function defaultBranchStock(): Record<BranchStockKey, string> {
    return { warehouse: "", qom: "", mashhad: "", najaf: "", tehran: "" };
}

export function toAsciiDigits(value: string | number | null | undefined): string {
    return String(value ?? "")
        .replace(/[۰-۹]/g, (d) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(d)))
        .replace(/[٠-٩]/g, (d) => String("٠١٢٣٤٥٦٧٨٩".indexOf(d)));
}

export function parsePriceDigits(value: string | number | null | undefined): string {
    return toAsciiDigits(value).replace(/\D/g, "");
}

export function formatPriceDisplay(value: string | number | null | undefined): string {
    const digits = parsePriceDigits(value);
    if (!digits) return "";
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

export function matchBranchKey(
    branch: { type?: string; city?: string; name?: string },
    key: BranchStockKey
): boolean {
    const city = (branch.city || "").toLowerCase();
    const name = (branch.name || "").toLowerCase();
    switch (key) {
        case "warehouse":
            return branch.type === "warehouse";
        case "qom":
            return city.includes("qom") || name.includes("قم");
        case "mashhad":
            return city.includes("mashhad") || name.includes("مشهد");
        case "najaf":
            return city.includes("najaf") || name.includes("نجف") || city.includes("iraq") || name.includes("عراق");
        case "tehran":
            return city.includes("tehran") || name.includes("طهران");
        default:
            return false;
    }
}

export function resolveBranchId(branches: any[], key: BranchStockKey): number | null {
    const branch = branches.find((b) => matchBranchKey(b, key));
    return branch ? Number(branch.id) : null;
}

export function branchStockFromInventories(
    inventories: any[] | undefined,
    branches: any[]
): Record<BranchStockKey, string> {
    const stock = defaultBranchStock();
    if (!inventories?.length) return stock;
    for (const key of BRANCH_STOCK_KEYS) {
        const branchId = resolveBranchId(branches, key);
        if (!branchId) continue;
        const inv = inventories.find((row) => Number(row.branch_id) === branchId);
        if (inv?.quantity != null) stock[key] = String(inv.quantity);
    }
    return stock;
}

export function totalBranchStock(stock: Record<string, string> | undefined): number {
    if (!stock) return 0;
    return Object.values(stock).reduce((sum, value) => sum + (parseInt(value, 10) || 0), 0);
}

export function sellingTomanForBranch(key: BranchStockKey, book: any): number {
    if (key === "mashhad") {
        return parseFloat(parsePriceDigits(book.priceTomanMashhad)) || parseFloat(parsePriceDigits(book.priceTomanQom)) || 0;
    }
    return parseFloat(parsePriceDigits(book.priceTomanQom)) || 0;
}

export function sellingDinarForBranch(book: any): number {
    return parseFloat(parsePriceDigits(book.priceDinar)) || 0;
}

/** Sell-price band used at intake: Mashhad POS, Najaf (dinar), everything else Qom toman. */
export type PriceBand = "qom" | "mashhad" | "najaf";

export function priceBandForBranch(branch: {
    type?: string | null;
    city?: string | null;
    name?: string | null;
}): PriceBand {
    if (matchBranchKey(branch, "mashhad")) return "mashhad";
    if (matchBranchKey(branch, "najaf")) return "najaf";
    return "qom";
}

export interface PriceBandValues {
    qom: number;
    mashhad: number;
    najaf: number;
}

export function emptyPriceBands(): PriceBandValues {
    return { qom: 0, mashhad: 0, najaf: 0 };
}

export function collectPriceBands(
    rows: Array<{
        branch_type?: string | null;
        branch_city?: string | null;
        branch_name?: string | null;
        price_toman?: number | null;
        price_dinar?: number | null;
    }> | undefined
): PriceBandValues {
    const bands = emptyPriceBands();
    if (!rows?.length) return bands;

    for (const row of rows) {
        const band = priceBandForBranch({
            type: row.branch_type,
            city: row.branch_city,
            name: row.branch_name,
        });
        if (band === "najaf") {
            const dinar = Number(row.price_dinar || 0);
            if (dinar > 0 && bands.najaf <= 0) bands.najaf = dinar;
            continue;
        }
        const toman = Number(row.price_toman || 0);
        if (toman > 0 && bands[band] <= 0) bands[band] = toman;
    }
    return bands;
}

export function assetValueByCurrency(
    rows: Array<{
        quantity?: number | null;
        price_toman?: number | null;
        price_dinar?: number | null;
    }> | undefined
): { toman: number; dinar: number } {
    return (rows || []).reduce(
        (acc, row) => {
            const qty = Number(row.quantity || 0);
            const dinar = Number(row.price_dinar || 0);
            const toman = Number(row.price_toman || 0);
            if (dinar > 0) acc.dinar += qty * dinar;
            else if (toman > 0) acc.toman += qty * toman;
            return acc;
        },
        { toman: 0, dinar: 0 }
    );
}

export const BOOK_CATEGORIES = ["دینی", "ادبی", "فقه", "عمومی", "کودک", "تاریخ"] as const;

export function resolveBookCoverUrl(path?: string | null): string | null {
    if (!path) return null;
    if (path.startsWith("http") || path.startsWith("blob:") || path.startsWith("data:")) return path;
    const api = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";
    const origin = api.replace(/\/api\/?$/, "");
    const clean = path.replace(/^\/storage\//, "").replace(/^storage\//, "");
    return `${origin}/storage/${clean}`;
}

export function parseDecimalInput(value: string): string {
    return toAsciiDigits(value)
        .replace(/[^\d.]/g, "")
        .replace(/(\..*)\./g, "$1");
}
