export type SettlementDisplayRow = {
    title: string;
    qty: number;
    remainingQty?: number;
    price: number;
    total: number;
    commission: number;
    publisherShare?: number;
    kind?: "sale" | "gift";
    branchId?: number | null;
    branchName?: string | null;
    bookId?: number | null;
};

function rowKey(item: SettlementDisplayRow): string {
    const book = item.bookId != null && item.bookId > 0 ? `b:${item.bookId}` : `t:${item.title}`;
    const kind = item.kind === "gift" ? "gift" : "sale";
    const branch = item.branchId != null ? String(item.branchId) : "";
    return `${branch}:${kind}:${book}`;
}

function shareOf(item: SettlementDisplayRow): number {
    return item.publisherShare ?? item.total - item.commission;
}

export function groupSettlementDisplayRows(items: SettlementDisplayRow[]): SettlementDisplayRow[] {
    const grouped = new Map<string, SettlementDisplayRow>();

    for (const item of items) {
        const key = rowKey(item);
        const existing = grouped.get(key);
        if (!existing) {
            grouped.set(key, { ...item, publisherShare: shareOf(item) });
            continue;
        }

        const qty = existing.qty + item.qty;
        const total = existing.total + item.total;
        const commission = existing.commission + item.commission;
        existing.qty = qty;
        existing.total = total;
        existing.commission = commission;
        existing.publisherShare = shareOf(existing) + shareOf(item);
        existing.remainingQty = Math.max(Number(existing.remainingQty ?? 0), Number(item.remainingQty ?? 0));
        existing.price = qty > 0 ? total / qty : existing.price;
        if (!existing.branchName && item.branchName) {
            existing.branchName = item.branchName;
        }
    }

    return Array.from(grouped.values());
}
