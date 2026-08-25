import { apiRequest } from "@/lib/api";
import {
    BRANCH_STOCK_KEYS,
    BranchStockKey,
    BranchLike,
    BookPriceFields,
    parsePriceDigits,
    resolveBranchId,
    sellingDinarForBranch,
    sellingTomanForBranch,
} from "@/lib/bookFormUtils";
import {
    intakeSupplierPayload,
    type SupplierAccountSelection,
} from "@/lib/supplierAccountSelection";

export type { BranchStockKey, SupplierAccountSelection };

function parsePrice(value: string | undefined): number {
    return parseFloat(parsePriceDigits(value)) || 0;
}

function costToman(book: BookPriceFields, selling: number): number | null {
    const raw = parsePrice(book.costPriceToman);
    if (raw > 0) return raw;
    return selling > 0 ? selling : null;
}

function costDinar(book: BookPriceFields, selling: number): number | null {
    const raw = parsePrice(book.costPriceDinar);
    if (raw > 0) return raw;
    return selling > 0 ? selling : null;
}

async function upsertBranchPricing(
    bookId: number,
    branchId: number,
    book: BookPriceFields,
    key: BranchStockKey
) {
    const isIraq = key === "najaf";
    const selling = isIraq ? sellingDinarForBranch(book) : sellingTomanForBranch(key, book);
    const type = book.type === "consignment" ? "consignment" : "owned";

    await apiRequest("/inventory/upsert-pricing", {
        method: "POST",
        body: JSON.stringify({
            branch_id: branchId,
            book_id: bookId,
            type,
            price_toman: isIraq ? null : selling > 0 ? selling : null,
            price_dinar: isIraq ? (selling > 0 ? selling : null) : null,
        }),
    });
}

/**
 * Sync per-branch stock + sell prices.
 * Sell prices for Qom / Mashhad / Najaf are saved even when quantity is 0.
 */
export async function syncBookBranchInventories(
    book: BookPriceFields,
    branches: BranchLike[],
    selection: SupplierAccountSelection | null,
    bookId: number,
    options?: {
        existingInventories?: Array<{ id: number; branch_id: number }>;
        /** Edit mode: write quantity for every resolved branch */
        syncQuantities?: boolean;
    }
) {
    const existing = options?.existingInventories || [];
    const syncQuantities = Boolean(options?.syncQuantities);

    for (const key of BRANCH_STOCK_KEYS) {
        const branchId = resolveBranchId(branches, key);
        if (!branchId) continue;

        const qty = parseInt(parsePriceDigits(book.branchStock?.[key]), 10) || 0;
        const isIraq = key === "najaf";
        const selling = isIraq ? sellingDinarForBranch(book) : sellingTomanForBranch(key, book);
        const existingRow = existing.find((inv) => Number(inv.branch_id) === branchId);
        const supplierFields = intakeSupplierPayload(selection, branchId);

        if (existingRow?.id) {
            const payload: Record<string, unknown> = {};
            if (syncQuantities) {
                payload.quantity = qty;
                payload.adjustment_reason = "ویرایش تعداد موجودی";
            }
            if (selling > 0) {
                payload.price_toman = isIraq ? null : selling;
                payload.price_dinar = isIraq ? selling : null;
            }
            if (Object.keys(payload).length === 0) continue;
            await apiRequest(`/inventory/${existingRow.id}`, {
                method: "PUT",
                body: JSON.stringify(payload),
            });
            continue;
        }

        if (qty > 0 && !syncQuantities) {
            const cost = isIraq
                ? costDinar(book, selling) || 0
                : costToman(book, selling) || 0;
            const currency = isIraq ? "dinar" : "toman";
            const priceToman = isIraq ? null : selling > 0 ? selling : null;
            const priceDinar = isIraq ? (selling > 0 ? selling : null) : null;

            if (book.type === "consignment") {
                if (!selection) {
                    throw new Error("supplier_required");
                }
                await apiRequest("/consignments", {
                    method: "POST",
                    body: JSON.stringify({
                        ...supplierFields,
                        branch_id: branchId,
                        currency,
                        received_at: book.settlementDate || new Date().toISOString().split("T")[0],
                        notes: book.notes || null,
                        items: [
                            {
                                book_id: bookId,
                                quantity: qty,
                                cost_price: cost,
                                selling_price: selling || cost,
                                price_toman: priceToman,
                                price_dinar: priceDinar,
                            },
                        ],
                    }),
                });
            } else {
                await apiRequest("/inventory/purchase", {
                    method: "POST",
                    body: JSON.stringify({
                        branch_id: branchId,
                        book_id: bookId,
                        quantity: qty,
                        currency,
                        cost_price: cost,
                        selling_price: selling || cost,
                        price_toman: priceToman,
                        price_dinar: priceDinar,
                        ...supplierFields,
                        notes: book.notes || null,
                        log_date: book.settlementDate || null,
                    }),
                });
            }
            continue;
        }

        if (selling > 0) {
            await upsertBranchPricing(bookId, branchId, book, key);
        } else if (syncQuantities && qty > 0) {
            await upsertBranchPricing(bookId, branchId, book, key);
        }
    }
}

/** Add stock to an existing title via lot intake (purchase or consignment). Never overwrites quantity. */
export async function addStockIntake(params: {
    bookId: number;
    branchId: number;
    quantity: number;
    type: "owned" | "consignment";
    selection: SupplierAccountSelection | null;
    currency: "toman" | "dinar";
    costPrice: number;
    sellingPrice: number;
    notes?: string | null;
    receivedAt?: string | null;
}) {
    const selling = params.sellingPrice;
    const cost = params.costPrice > 0 ? params.costPrice : selling;
    const date = params.receivedAt || new Date().toISOString().split("T")[0];
    const priceToman = params.currency === "toman" ? selling : null;
    const priceDinar = params.currency === "dinar" ? selling : null;
    const supplierFields = intakeSupplierPayload(params.selection, params.branchId);

    if (params.type === "consignment") {
        if (!params.selection) {
            throw new Error("supplier_required");
        }
        return apiRequest("/consignments", {
            method: "POST",
            body: JSON.stringify({
                ...supplierFields,
                branch_id: params.branchId,
                currency: params.currency,
                received_at: date,
                notes: params.notes || null,
                items: [
                    {
                        book_id: params.bookId,
                        quantity: params.quantity,
                        cost_price: cost,
                        selling_price: selling || cost,
                        price_toman: priceToman,
                        price_dinar: priceDinar,
                    },
                ],
            }),
        });
    }

    const resolvedCost = cost > 0 ? cost : selling;
    if (resolvedCost <= 0) {
        throw new Error("price_required");
    }

    return apiRequest("/inventory/purchase", {
        method: "POST",
        body: JSON.stringify({
            branch_id: params.branchId,
            book_id: params.bookId,
            quantity: params.quantity,
            currency: params.currency,
            cost_price: resolvedCost,
            selling_price: selling || cost,
            price_toman: priceToman,
            price_dinar: priceDinar,
            ...supplierFields,
            notes: params.notes || null,
            log_date: date,
        }),
    });
}

export function bookPayloadFromForm(book: BookPriceFields) {
    return {
        title: book.title,
        author: typeof book.author === "string" && book.author.trim() ? book.author.trim() : "",
        isbn: book.isbn || null,
        publisher: book.publisher || null,
        size: book.size || null,
        cover: book.cover || null,
        publication_year: book.publicationYear || null,
        cover_image: book.coverImage || null,
        weight: book.weight !== "" && book.weight != null ? Number(book.weight) : null,
        weight_with_packaging:
            book.weightWithPackaging !== "" && book.weightWithPackaging != null
                ? Number(book.weightWithPackaging)
                : null,
        volume_count: book.volumeCount !== "" && book.volumeCount != null ? Number(book.volumeCount) : null,
        category: book.category || null,
        description: book.notes || null,
        iraq_only: Boolean(book.iraqOnly),
        low_stock_threshold:
            book.low_stock_threshold !== "" && book.low_stock_threshold != null
                ? Number(book.low_stock_threshold)
                : null,
    };
}

export function defaultBookFormState() {
    return {
        title: "",
        author: "",
        isbn: "",
        publisher: "",
        size: "",
        cover: "",
        publicationYear: "",
        coverImage: "",
        coverImagePreview: "",
        weight: "",
        weightWithPackaging: "",
        volumeCount: "1",
        category: "",
        type: "consignment",
        priceTomanQom: "",
        priceTomanMashhad: "",
        priceDinar: "",
        costPriceToman: "",
        costPriceDinar: "",
        branchStock: {
            warehouse: "",
            qom: "",
            mashhad: "",
            najaf: "",
            tehran: "",
        },
        settlementDate: "",
        notes: "",
        unpaidSales: "0",
        totalSales: "0",
        iraqOnly: false,
        language: "fa",
        low_stock_threshold: "5",
    };
}
