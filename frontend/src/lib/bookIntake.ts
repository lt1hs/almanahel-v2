import { apiRequest } from "@/lib/api";
import {
    BRANCH_STOCK_KEYS,
    BranchStockKey,
    parsePriceDigits,
    resolveBranchId,
    sellingDinarForBranch,
    sellingTomanForBranch,
} from "@/lib/bookFormUtils";

function parsePrice(value: string | undefined): number {
    return parseFloat(parsePriceDigits(value)) || 0;
}

function costToman(book: any, selling: number): number {
    const raw = parsePrice(book.costPriceToman);
    return raw > 0 ? raw : selling;
}

function costDinar(book: any, selling: number): number {
    const raw = parsePrice(book.costPriceDinar);
    return raw > 0 ? raw : selling;
}

export async function syncBookBranchInventories(
    book: any,
    branches: any[],
    supplierId: number | null,
    bookId: number
) {
    for (const key of BRANCH_STOCK_KEYS) {
        const branchId = resolveBranchId(branches, key);
        if (!branchId) continue;

        const qty = parseInt(parsePriceDigits(book.branchStock?.[key]), 10) || 0;
        if (qty <= 0) continue;

        const isIraq = key === "najaf";
        const selling = isIraq ? sellingDinarForBranch(book) : sellingTomanForBranch(key, book);
        const cost = isIraq ? costDinar(book, selling) : costToman(book, selling);
        const currency = isIraq ? "dinar" : "toman";
        const priceToman = isIraq ? null : selling;
        const priceDinar = isIraq ? selling : null;

        if (book.type === "consignment" && supplierId) {
            await apiRequest("/consignments", {
                method: "POST",
                body: JSON.stringify({
                    supplier_id: supplierId,
                    branch_id: branchId,
                    currency,
                    received_at: book.settlementDate || new Date().toISOString().split("T")[0],
                    notes: book.notes || null,
                    items: [{
                        book_id: bookId,
                        quantity: qty,
                        cost_price: cost,
                        selling_price: selling,
                        price_toman: priceToman,
                        price_dinar: priceDinar,
                    }],
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
                    selling_price: selling,
                    price_toman: priceToman,
                    price_dinar: priceDinar,
                    supplier_id: supplierId,
                    notes: book.notes || null,
                    log_date: book.settlementDate || null,
                }),
            });
        }
    }
}

export function bookPayloadFromForm(book: any) {
    return {
        title: book.title,
        author: book.author,
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
        low_stock_threshold: book.low_stock_threshold ? Number(book.low_stock_threshold) : 5,
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
    };
}

export type { BranchStockKey };
