import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
    buildBulkSettlementPayload,
    mapUnsettledRowToBulkDebtRow,
} from "./bulkSettlementRows";

const LARGE_DECIMAL = "999999999999999999.99";

describe("bulk settlement decimal contract", () => {
    it("preserves exact balance and expected_total strings through mutation payload", () => {
        const apiRow = {
            supplier_account_id: 42,
            supplier_id: 9,
            branch_id: 3,
            display_name: "Publisher A",
            currency: "toman",
            balance: LARGE_DECIMAL,
            expected_total: LARGE_DECIMAL,
        };

        const mapped = mapUnsettledRowToBulkDebtRow(apiRow, "toman");
        assert.ok(mapped);
        assert.equal(mapped.balance, LARGE_DECIMAL);
        assert.equal(mapped.expectedTotal, LARGE_DECIMAL);

        const payload = buildBulkSettlementPayload([mapped], {
            start: "2026-08-01",
            end: "2026-08-22",
        });

        assert.equal(payload.settlements[0].amount, LARGE_DECIMAL);
        assert.equal(payload.settlements[0].expected_total, LARGE_DECIMAL);
        assert.equal(typeof payload.settlements[0].amount, "string");
        assert.equal(typeof payload.settlements[0].expected_total, "string");
    });

    it("does not coerce decimal strings through Number()", () => {
        const row = mapUnsettledRowToBulkDebtRow(
            {
                supplier_account_id: 1,
                supplier_id: 1,
                branch_id: 1,
                balance: "123456789012345678.01",
                expected_total: "123456789012345678.01",
                currency: "dinar",
            },
            "dinar"
        );
        assert.ok(row);
        assert.notEqual(row.balance, Number("123456789012345678.01"));
        assert.equal(row.balance, "123456789012345678.01");
    });
});
