export const BRANCH_STOCK_KEYS = ["warehouse", "qom", "mashhad", "najaf", "tehran"] as const;
export type BranchStockKey = (typeof BRANCH_STOCK_KEYS)[number];

/** Branch row as returned by the API (capabilities optional for older payloads). */
export interface BranchLike {
    id?: number | null;
    type?: string | null;
    city?: string | null;
    name?: string | null;
    is_central_warehouse?: boolean | null;
    is_intake_hub?: boolean | null;
    is_iraq_store?: boolean | null;
    supports_dinar?: boolean | null;
    supports_toman?: boolean | null;
}

export interface BookPriceFields {
    costPriceToman?: string;
    costPriceDinar?: string;
    priceTomanQom?: string;
    priceTomanMashhad?: string;
    priceDinar?: string;
    type?: string;
    title?: string;
    author?: string;
    isbn?: string;
    publisher?: string;
    size?: string;
    cover?: string;
    branchStock?: Record<string, string>;
    [key: string]: unknown;
}

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

/** True when the branch payload includes at least one capability flag. */
export function hasBranchCapabilities(branch: BranchLike | null | undefined): boolean {
    if (!branch) return false;
    return (
        branch.is_central_warehouse != null
        || branch.is_intake_hub != null
        || branch.is_iraq_store != null
        || branch.supports_dinar != null
        || branch.supports_toman != null
    );
}

export function isCentralWarehouse(branch: BranchLike | null | undefined): boolean {
    if (!branch) return false;
    if (branch.is_central_warehouse != null) return Boolean(branch.is_central_warehouse);
    return branch.type === "warehouse";
}

export function isIntakeHub(branch: BranchLike | null | undefined): boolean {
    if (!branch) return false;
    if (branch.is_intake_hub != null) return Boolean(branch.is_intake_hub);
    return isCentralWarehouse(branch) || matchBranchKeyHeuristic(branch, "qom");
}

export function isIraqStore(branch: BranchLike | null | undefined): boolean {
    if (!branch) return false;
    if (branch.is_iraq_store != null) return Boolean(branch.is_iraq_store);
    return matchBranchKeyHeuristic(branch, "najaf");
}

export function branchSupportsDinar(branch: BranchLike | null | undefined): boolean {
    if (!branch) return false;
    if (branch.supports_dinar != null) return Boolean(branch.supports_dinar);
    return isIraqStore(branch);
}

export function branchSupportsToman(branch: BranchLike | null | undefined): boolean {
    if (!branch) return true;
    if (branch.supports_toman != null) return Boolean(branch.supports_toman);
    return !isIraqStore(branch);
}

function matchBranchKeyHeuristic(branch: BranchLike, key: BranchStockKey): boolean {
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
            return (
                city.includes("najaf")
                || city.includes("نجف")
                || name.includes("نجف")
                || city.includes("iraq")
                || name.includes("عراق")
            );
        case "tehran":
            return city.includes("tehran") || name.includes("طهران") || name.includes("تهران");
        default:
            return false;
    }
}

/**
 * Match a stock key to a branch. Prefers API capability flags when present;
 * falls back to type / city / name heuristics.
 */
export function matchBranchKey(
    branch: BranchLike | null | undefined,
    key: BranchStockKey
): boolean {
    if (!branch) return false;

    if (hasBranchCapabilities(branch)) {
        switch (key) {
            case "warehouse":
                if (branch.is_central_warehouse != null) return Boolean(branch.is_central_warehouse);
                break;
            case "qom":
                // Intake hub that is not the central warehouse = Qom store.
                if (branch.is_intake_hub != null) {
                    return Boolean(branch.is_intake_hub) && !Boolean(branch.is_central_warehouse);
                }
                break;
            case "najaf":
                if (branch.is_iraq_store != null) return Boolean(branch.is_iraq_store);
                if (branch.supports_dinar != null && branch.supports_dinar) return true;
                break;
            case "mashhad":
            case "tehran":
                // No dedicated capability flags — use heuristics below.
                break;
            default:
                break;
        }
    }

    return matchBranchKeyHeuristic(branch, key);
}

export function resolveBranchId(branches: BranchLike[], key: BranchStockKey): number | null {
    const branch = branches.find((b) => matchBranchKey(b, key));
    return branch?.id != null ? Number(branch.id) : null;
}

/** Best stock-form key for a user's branch (POS vs warehouse). */
export function stockKeyForBranch(branch: BranchLike | null | undefined): BranchStockKey | null {
    if (!branch) return null;
    const order: BranchStockKey[] = ["warehouse", "najaf", "mashhad", "tehran", "qom"];
    for (const key of order) {
        if (matchBranchKey(branch, key)) return key;
    }
    return null;
}

export function branchStockFromInventories(
    inventories: Array<{ branch_id?: number | null; quantity?: number | null }> | undefined,
    branches: BranchLike[]
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

export function sellingTomanForBranch(key: BranchStockKey, book: BookPriceFields): number {
    if (key === "mashhad") {
        return parseFloat(parsePriceDigits(book.priceTomanMashhad)) || parseFloat(parsePriceDigits(book.priceTomanQom)) || 0;
    }
    return parseFloat(parsePriceDigits(book.priceTomanQom)) || 0;
}

export function sellingDinarForBranch(book: BookPriceFields): number {
    return parseFloat(parsePriceDigits(book.priceDinar)) || 0;
}

/** Sell-price band used at intake: Mashhad POS, Najaf (dinar), everything else Qom toman. */
export type PriceBand = "qom" | "mashhad" | "najaf";

export function priceBandForBranch(branch: BranchLike | null | undefined): PriceBand {
    if (!branch) return "qom";
    if (hasBranchCapabilities(branch)) {
        if (branch.is_iraq_store || (branch.supports_dinar && !branch.supports_toman)) {
            return "najaf";
        }
    }
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
        is_central_warehouse?: boolean | null;
        is_intake_hub?: boolean | null;
        is_iraq_store?: boolean | null;
        supports_dinar?: boolean | null;
        supports_toman?: boolean | null;
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
            is_central_warehouse: row.is_central_warehouse,
            is_intake_hub: row.is_intake_hub,
            is_iraq_store: row.is_iraq_store,
            supports_dinar: row.supports_dinar,
            supports_toman: row.supports_toman,
        });
        const dinar = Number(row.price_dinar || 0);
        const toman = Number(row.price_toman || 0);
        if (band === "najaf") {
            if (dinar > 0 && bands.najaf <= 0) bands.najaf = dinar;
            else if (toman > 0 && bands.najaf <= 0) bands.najaf = toman;
            continue;
        }
        if (toman > 0 && bands[band] <= 0) bands[band] = toman;
        else if (dinar > 0 && bands.najaf <= 0) bands.najaf = dinar;
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
