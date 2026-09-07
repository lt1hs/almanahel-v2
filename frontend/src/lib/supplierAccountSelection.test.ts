import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
    inventoryCanonicalSupplierId,
    pickInventoryForSupplierPrefill,
    selectionMatchingCanonicalSupplier,
    supplierAccountsAggregateUrl,
    supplierAccountsUrl,
    uniqueCanonicalSuppliers,
    type SupplierAccountSelection,
} from "./supplierAccountSelection";

describe("supplierAccountsAggregateUrl", () => {
    it("asks for the financial aggregate list", () => {
        assert.equal(
            supplierAccountsAggregateUrl(true),
            "/supplier-accounts?aggregate=1&financial=1"
        );
    });

    it("does not change the branch-scoped list helper", () => {
        assert.equal(
            supplierAccountsUrl(7, true),
            "/supplier-accounts?branch_id=7&financial=1"
        );
    });
});

describe("uniqueCanonicalSuppliers", () => {
    it("dedupes by supplier_id and skips local-only rows", () => {
        assert.deepEqual(
            uniqueCanonicalSuppliers([
                { id: 1, supplier_id: 10, display_name: "Qom" },
                { id: 2, supplier_id: 10, display_name: "Mashhad" },
                { id: 3, supplier_id: null, display_name: "Local" },
                { id: 4, supplier_id: 11, name: "Other" },
            ]),
            [
                { id: 10, name: "Qom" },
                { id: 11, name: "Other" },
            ]
        );
    });
});

describe("inventoryCanonicalSupplierId", () => {
    it("prefers inventory.supplier_id over the nested relation", () => {
        assert.equal(
            inventoryCanonicalSupplierId({
                supplier_id: 12,
                supplier: { id: 99, name: "Other" },
            }),
            12
        );
    });

    it("falls back to supplier.id when the column is empty", () => {
        assert.equal(
            inventoryCanonicalSupplierId({
                supplier_id: null,
                supplier: { id: 7, name: "Pub" },
            }),
            7
        );
    });

    it("returns null when the book has no supplier", () => {
        assert.equal(inventoryCanonicalSupplierId({}), null);
        assert.equal(inventoryCanonicalSupplierId(null), null);
    });
});

describe("pickInventoryForSupplierPrefill", () => {
    const rows = [
        { branch_id: 1, supplier_id: null },
        { branch_id: 2, supplier_id: 40, supplier: { id: 40, name: "sup from admin" } },
        { branch_id: 3, supplier_id: 41 },
    ];

    it("uses the selected branch when that inventory has a supplier", () => {
        assert.equal(pickInventoryForSupplierPrefill(rows, 3)?.supplier_id, 41);
    });

    it("falls back to another inventory that has a supplier", () => {
        assert.equal(pickInventoryForSupplierPrefill(rows, 1)?.supplier_id, 40);
    });
});

describe("selectionMatchingCanonicalSupplier", () => {
    const accounts: SupplierAccountSelection[] = [
        { accountId: 101, canonicalSupplierId: 40, branchId: 2, name: "sup from admin" },
        { accountId: 202, canonicalSupplierId: 41, branchId: 2, name: "other" },
    ];

    it("matches the branch account for the book's canonical supplier", () => {
        assert.deepEqual(
            selectionMatchingCanonicalSupplier(accounts, 40),
            accounts[0]
        );
    });

    it("returns null when the supplier is not in this branch's accounts", () => {
        assert.equal(selectionMatchingCanonicalSupplier(accounts, 99), null);
    });
});
