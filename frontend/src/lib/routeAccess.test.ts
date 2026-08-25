import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { canAccessRoute, normalizeDashboardPath } from "./routeAccess.js";

describe("canAccessRoute", () => {
    it("blocks warehouse_staff from POS and finance URLs", () => {
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/sales"), false);
        assert.equal(canAccessRoute("warehouse_staff", "/fa/dashboard/sales/"), false);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/expenses"), false);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/admin"), false);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/customers"), false);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/customers/detail"), false);
    });

    it("allows warehouse_staff on warehouse and inventory", () => {
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard"), true);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/warehouse"), true);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/warehouse/log"), true);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/inventory"), true);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/inventory/new"), true);
        assert.equal(canAccessRoute("warehouse_staff", "/dashboard/distribution"), true);
    });

    it("keeps accountant on finance/reports without locking them from dashboard", () => {
        assert.equal(canAccessRoute("accountant", "/dashboard/finance"), true);
        assert.equal(canAccessRoute("accountant", "/dashboard/reports"), true);
        assert.equal(canAccessRoute("accountant", "/dashboard/finance/branch-profit"), false);
        assert.equal(canAccessRoute("accountant", "/dashboard/admin"), false);
    });

    it("allows branch ops on customers directory and blocks accountant", () => {
        assert.equal(canAccessRoute("accountant", "/dashboard/customers"), false);
        assert.equal(canAccessRoute("branch_manager", "/dashboard/customers"), true);
        assert.equal(canAccessRoute("branch_manager", "/fa/dashboard/customers/detail"), true);
    });

    it("normalizes locale prefixes", () => {
        assert.equal(normalizeDashboardPath("/fa/dashboard/sales/"), "/dashboard/sales");
        assert.equal(normalizeDashboardPath("/ar/dashboard/warehouse/log"), "/dashboard/warehouse/log");
    });
});
