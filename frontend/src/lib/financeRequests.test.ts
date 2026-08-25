import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
    ALL_TIME_DEBT_PERIOD_START,
    buildFinanceOverviewUrls,
    buildSettlementHistoryScopeKey,
    buildSettlementHistoryUrl,
    buildUnsettledDebtUrl,
    canSeeIraqLedgerTab,
} from "./financeRequests";

describe("buildUnsettledDebtUrl", () => {
    const today = "2026-08-22";

    it("admin uses aggregate reporting with all-time period", () => {
        const url = buildUnsettledDebtUrl({ role: "admin", today });
        assert.equal(
            url,
            `/consignments/unsettled-by-supplier?aggregate=1&period_start=${ALL_TIME_DEBT_PERIOD_START}&period_end=${today}`
        );
    });

    it("super_admin uses aggregate reporting with all-time period", () => {
        const url = buildUnsettledDebtUrl({ role: "super_admin", today });
        assert.match(url ?? "", /aggregate=1/);
        assert.match(url ?? "", /period_start=1970-01-01/);
        assert.match(url ?? "", /period_end=2026-08-22/);
    });

    it("branch manager is branch-scoped with required period", () => {
        const url = buildUnsettledDebtUrl({ role: "branch_manager", branchId: 7, today });
        assert.equal(
            url,
            `/consignments/unsettled-by-supplier?branch_id=7&period_start=${ALL_TIME_DEBT_PERIOD_START}&period_end=${today}`
        );
    });

    it("assigned accountant is branch-scoped with required period", () => {
        const url = buildUnsettledDebtUrl({ role: "accountant", branchId: 3, today });
        assert.equal(
            url,
            `/consignments/unsettled-by-supplier?branch_id=3&period_start=${ALL_TIME_DEBT_PERIOD_START}&period_end=${today}`
        );
    });

    it("unassigned accountant returns null (no silent zero KPI)", () => {
        assert.equal(buildUnsettledDebtUrl({ role: "accountant", branchId: null, today }), null);
        assert.equal(buildUnsettledDebtUrl({ role: "accountant", today }), null);
    });
});

describe("buildFinanceOverviewUrls", () => {
    it("does not call HQ all-branches for a Qom/Mashhad branch manager", () => {
        const urls = buildFinanceOverviewUrls({
            role: "branch_manager",
            currency: "toman",
            branchId: 4,
            today: "2026-08-25",
            monthStart: "2026-08-01",
        });
        const blob = `${urls.topBooks} ${urls.pnl} ${urls.treasury}`;
        assert.equal(blob.includes("/reports/all-branches"), false);
        assert.equal(urls.topBooks.includes("branch_id=4"), true);
        assert.equal(urls.pnl.includes("branch_id=4"), true);
        assert.equal(urls.treasury.includes("branch_id=4"), true);
    });

    it("keeps HQ overview unscoped for admin", () => {
        const urls = buildFinanceOverviewUrls({
            role: "admin",
            currency: "dinar",
            today: "2026-08-25",
            monthStart: "2026-08-01",
        });
        assert.equal(urls.pnl.includes("branch_id="), false);
        assert.match(urls.pnl, /currency=dinar/);
    });
});

describe("canSeeIraqLedgerTab", () => {
    it("is admin-only so Qom and Mashhad branch managers do not see Iraq P&L", () => {
        assert.equal(canSeeIraqLedgerTab("admin"), true);
        assert.equal(canSeeIraqLedgerTab("super_admin"), true);
        assert.equal(canSeeIraqLedgerTab("branch_manager"), false);
        assert.equal(canSeeIraqLedgerTab("accountant"), false);
        assert.equal(canSeeIraqLedgerTab(undefined), false);
    });
});

describe("settlement history scope", () => {
    it("branch scope produces stable request key", () => {
        const key = buildSettlementHistoryScopeKey({ aggregate: false, branchId: "12" });
        assert.equal(key, "branch:12");
        assert.equal(
            buildSettlementHistoryUrl({ aggregate: false, branchId: "12" }),
            "/consignments/settlements?branch_id=12"
        );
    });

    it("aggregate scope produces distinct key and URL", () => {
        const branchKey = buildSettlementHistoryScopeKey({ aggregate: false, branchId: 5 });
        const aggregateKey = buildSettlementHistoryScopeKey({ aggregate: true, branchId: null });
        assert.notEqual(branchKey, aggregateKey);
        assert.equal(aggregateKey, "aggregate");
        assert.equal(
            buildSettlementHistoryUrl({ aggregate: true, branchId: null }),
            "/consignments/settlements?aggregate=1"
        );
    });

    it("changing branch changes scope key exactly once per branch", () => {
        const keys = new Set([
            buildSettlementHistoryScopeKey({ aggregate: false, branchId: 1 }),
            buildSettlementHistoryScopeKey({ aggregate: false, branchId: 2 }),
            buildSettlementHistoryScopeKey({ aggregate: false, branchId: 1 }),
        ]);
        assert.equal(keys.size, 2);
    });

    it("missing branch without aggregate yields null scope (no fetch)", () => {
        assert.equal(buildSettlementHistoryScopeKey({ aggregate: false, branchId: "" }), null);
        assert.equal(buildSettlementHistoryUrl({ aggregate: false, branchId: "" }), null);
    });
});
