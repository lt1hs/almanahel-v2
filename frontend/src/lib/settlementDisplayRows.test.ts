import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { groupSettlementDisplayRows } from "./settlementDisplayRows";

describe("groupSettlementDisplayRows", () => {
    it("collapses same-book sale allocations and does not repeat remaining stock", () => {
        const rows = groupSettlementDisplayRows([
            { title: "BOOK FROM ADMIN", bookId: 1, qty: 10, remainingQty: 16, price: 500000, total: 5000000, commission: 0, publisherShare: 5000000, kind: "sale" },
            { title: "BOOK FROM ADMIN", bookId: 1, qty: 7, remainingQty: 16, price: 500000, total: 3500000, commission: 0, publisherShare: 3500000, kind: "sale" },
            { title: "BOOK FROM ADMIN", bookId: 1, qty: 1, remainingQty: 16, price: 500000, total: 500000, commission: 0, publisherShare: 500000, kind: "sale" },
            { title: "BOOK FROM ADMIN", bookId: 1, qty: 1, remainingQty: 16, price: 500000, total: 500000, commission: 0, publisherShare: 500000, kind: "sale" },
            { title: "BOOK FROM ADMIN", bookId: 1, qty: 1, remainingQty: 16, price: 500000, total: 500000, commission: 0, publisherShare: 500000, kind: "sale" },
            { title: "BOOK FROM ADMIN", bookId: 1, qty: 3, remainingQty: 16, price: 500000, total: 1500000, commission: 0, publisherShare: 1500000, kind: "sale" },
        ]);

        assert.equal(rows.length, 1);
        assert.equal(rows[0]?.qty, 23);
        assert.equal(rows[0]?.remainingQty, 16);
        assert.equal(rows[0]?.total, 11500000);
        assert.equal(rows[0]?.publisherShare, 11500000);
    });

    it("keeps gifts and other branches as separate rows", () => {
        const rows = groupSettlementDisplayRows([
            { title: "A", bookId: 1, qty: 2, remainingQty: 4, price: 10, total: 20, commission: 0, publisherShare: 20, kind: "sale", branchId: 1 },
            { title: "A", bookId: 1, qty: 1, remainingQty: 9, price: 10, total: 10, commission: 0, publisherShare: 10, kind: "sale", branchId: 2 },
            { title: "A", bookId: 1, qty: 1, remainingQty: 4, price: 10, total: 10, commission: 0, publisherShare: 10, kind: "gift", branchId: 1 },
        ]);

        assert.equal(rows.length, 3);
        const qomSale = rows.find((row) => row.kind === "sale" && row.branchId === 1);
        assert.equal(qomSale?.qty, 2);
        assert.equal(qomSale?.remainingQty, 4);
    });
});
