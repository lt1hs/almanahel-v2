import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
    isAdminOperationalRole,
    resolveDefaultOperationalBranchId,
    shouldLockBranchSelector,
} from "./operationalBranch.js";

describe("resolveDefaultOperationalBranchId", () => {
    it("non-admin uses assigned branch immediately", () => {
        const id = resolveDefaultOperationalBranchId(
            { role: "branch_manager", branch_id: 4 },
            { availableBranchIds: [4, 9] }
        );
        assert.equal(id, 4);
    });

    it("admin prefers persisted then assigned then central", () => {
        assert.equal(
            resolveDefaultOperationalBranchId(
                { role: "admin", branch_id: 2 },
                { availableBranchIds: [1, 2, 3], persistedBranchId: 3, centralBranchId: 1 }
            ),
            3
        );
        assert.equal(
            resolveDefaultOperationalBranchId(
                { role: "admin", branch_id: 2 },
                { availableBranchIds: [1, 2, 3], persistedBranchId: null, centralBranchId: 1 }
            ),
            2
        );
        assert.equal(
            resolveDefaultOperationalBranchId(
                { role: "super_admin", branch_id: null },
                { availableBranchIds: [1, 3], persistedBranchId: null, centralBranchId: 1 }
            ),
            1
        );
    });

    it("ignores persisted branch outside available set", () => {
        assert.equal(
            resolveDefaultOperationalBranchId(
                { role: "admin", branch_id: 2 },
                { availableBranchIds: [2], persistedBranchId: 99 }
            ),
            2
        );
    });

    it("returns null when admin has no valid default", () => {
        assert.equal(
            resolveDefaultOperationalBranchId(
                { role: "admin" },
                { availableBranchIds: [1, 2], persistedBranchId: null, centralBranchId: null }
            ),
            null
        );
    });
});

describe("branch selector lock", () => {
    it("locks for non-admin", () => {
        assert.equal(shouldLockBranchSelector({ role: "warehouse_staff" }), true);
        assert.equal(shouldLockBranchSelector({ role: "admin" }), false);
        assert.equal(isAdminOperationalRole("super_admin"), true);
    });
});
