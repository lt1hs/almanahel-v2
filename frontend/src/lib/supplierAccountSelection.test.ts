import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
    supplierAccountsAggregateUrl,
    supplierAccountsUrl,
    uniqueCanonicalSuppliers,
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
