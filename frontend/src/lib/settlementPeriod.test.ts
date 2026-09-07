import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
    buildSettlementBody,
    buildSettlementPreviewUrl,
    defaultSettlementPeriod,
    invoicePeriodFromSettlement,
    periodPreset,
} from "./settlementPeriod";

describe("defaultSettlementPeriod", () => {
    it("defaults to all-open, not the current calendar month", () => {
        const now = new Date(2026, 8, 7);
        const period = defaultSettlementPeriod(now);
        assert.equal(period.preset, "all");
        assert.equal(period.from, "");
        assert.equal(period.to, "2026-09-07");
        const month = periodPreset("month", now);
        assert.equal(month.from, "2026-09-01");
        assert.notEqual(period.from, month.from);
    });
});

describe("buildSettlementPreviewUrl", () => {
    it("sends all_open and omits a month-bounded period", () => {
        const url = buildSettlementPreviewUrl({
            supplierAccountId: 9,
            currency: "toman",
            branchId: 4,
            allOpen: true,
            fromDate: "2026-09-01",
            toDate: "2026-09-07",
        });
        assert.equal(
            url,
            "/consignments/settlement-preview?supplier_account_id=9&all_open=1&period_start=1970-01-01&period_end=2026-09-07&currency=toman&branch_id=4"
        );
        assert.match(url, /all_open=1/);
    });

    it("accepts a string branch id from a select input", () => {
        const url = buildSettlementPreviewUrl({
            supplierAccountId: 9,
            currency: "toman",
            branchId: "4",
        });
        assert.match(url, /branch_id=4/);
        const body = buildSettlementBody({
            supplierAccountId: 9,
            amount: 1,
            currency: "toman",
            branchId: "4",
        });
        assert.equal(body.branch_id, 4);
    });

    it("keeps explicit dates when all_open is off", () => {
        const url = buildSettlementPreviewUrl({
            supplierAccountId: 9,
            currency: "toman",
            branchId: 4,
            fromDate: "2026-09-01",
            toDate: "2026-09-07",
        });
        assert.match(url, /period_start=2026-09-01/);
        assert.match(url, /period_end=2026-09-07/);
        assert.equal(url.includes("all_open"), false);
    });
});

describe("buildSettlementBody", () => {
    it("confirms all remaining without a truncated month range", () => {
        const body = buildSettlementBody({
            supplierAccountId: 9,
            amount: 11500000,
            currency: "toman",
            branchId: 4,
            allOpen: true,
            expectedTotal: "11500000.00",
        });
        assert.equal(body.all_open, true);
        assert.equal(body.period_start, "1970-01-01");
        assert.equal(body.supplier_account_id, 9);
        assert.equal(body.expected_total, "11500000.00");
    });
});

describe("invoicePeriodFromSettlement", () => {
    it("uses dates returned by all-open settle for the invoice", () => {
        const period = invoicePeriodFromSettlement(true, "", "", {
            period_start: "2026-08-24",
            period_end: "2026-09-07",
        });
        assert.equal(period.from, "2026-08-24");
        assert.equal(period.to, "2026-09-07");
    });
});
