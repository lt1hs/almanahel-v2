import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
    buildBookByBarcodeUrl,
    buildBookLowStockUrl,
    buildBookShowUrl,
    buildBooksListUrl,
    buildInventoryBookBranchesUrl,
    buildPostBooksPayload,
    isBarcodeNewBookCandidate,
    resolveOperationalBranchId,
} from "./bookCatalogRequests.js";

describe("resolveOperationalBranchId", () => {
    it("admin requires explicit branch and never uses assigned branch", () => {
        const result = resolveOperationalBranchId({ role: "admin", branch_id: 99 }, null);
        assert.equal(result.branchId, null);
        assert.equal(result.error, "branch_required");

        const ok = resolveOperationalBranchId({ role: "admin", branch_id: 99 }, 5);
        assert.equal(ok.branchId, 5);
    });

    it("branch manager is locked to own branch", () => {
        const ok = resolveOperationalBranchId({ role: "branch_manager", branch_id: 3 });
        assert.equal(ok.branchId, 3);

        const forged = resolveOperationalBranchId({ role: "branch_manager", branch_id: 3 }, 7);
        assert.equal(forged.error, "forbidden");
    });

    it("warehouse staff is locked to own branch", () => {
        const ok = resolveOperationalBranchId({ role: "warehouse_staff", branch_id: 8 });
        assert.equal(ok.branchId, 8);
    });
});

describe("buildBooksListUrl", () => {
    it("admin list includes selected branch", () => {
        const url = buildBooksListUrl({ role: "admin" }, { branchId: 12, lite: true, search: "abc" });
        assert.match(url!, /branch_id=12/);
        assert.match(url!, /lite=1/);
        assert.match(url!, /search=abc/);
    });

    it("branch manager list omits explicit branch param when implicit", () => {
        const url = buildBooksListUrl({ role: "branch_manager", branch_id: 4 }, { lite: true });
        assert.match(url!, /branch_id=4/);
    });

    it("admin without branch returns null", () => {
        assert.equal(buildBooksListUrl({ role: "admin" }, { lite: true }), null);
    });
});

describe("buildBookShowUrl", () => {
    it("admin edit uses explicit branch", () => {
        const url = buildBookShowUrl(42, { role: "admin" }, 6);
        assert.equal(url, "/books/42?branch_id=6");
    });
});

describe("buildBookByBarcodeUrl", () => {
    it("barcode lookup always includes branch", () => {
        const url = buildBookByBarcodeUrl("978123", { role: "branch_manager", branch_id: 2 });
        assert.equal(url, "/books/by-barcode/978123?branch_id=2");
    });
});

describe("buildInventoryBookBranchesUrl", () => {
    it("transfer source branch scopes inventory lookup", () => {
        const url = buildInventoryBookBranchesUrl(9, { role: "admin" }, 15);
        assert.equal(url, "/inventory/books/9/branches?branch_id=15");
    });
});

describe("buildPostBooksPayload", () => {
    it("consignment/intake always sends branch_id", () => {
        const result = buildPostBooksPayload({ role: "branch_manager", branch_id: 1 }, { title: "X" });
        assert.ok("payload" in result);
        assert.equal(result.payload.branch_id, 1);
    });

    it("admin add-stock requires branch", () => {
        const result = buildPostBooksPayload({ role: "admin" }, { title: "X" });
        assert.ok("error" in result);
        assert.equal(result.error, "branch_required");
    });
});

describe("buildBookLowStockUrl", () => {
    it("reports low stock for accountant branch", () => {
        const url = buildBookLowStockUrl({ role: "accountant", branch_id: 11 });
        assert.equal(url, "/books/low-stock?branch_id=11");
    });
});

describe("isBarcodeNewBookCandidate", () => {
    it("catalog 404 is not treated as new ISBN", () => {
        assert.equal(isBarcodeNewBookCandidate(404, "این عنوان در کاتالوگ شعبه شما نیست"), false);
    });

    it("canonical missing 404 may start new intake", () => {
        assert.equal(isBarcodeNewBookCandidate(404, "کتابی با این بارکد یافت نشد"), true);
    });

    it("403 and 422 never start new intake", () => {
        assert.equal(isBarcodeNewBookCandidate(403, "دسترسی"), false);
        assert.equal(isBarcodeNewBookCandidate(422, "branch_required"), false);
    });
});
