import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
  isInternalAppHref,
  parseAppLocation,
  sameDestination,
  shouldStartNavigation,
} from "./navigationUrl.js";

const HERE = "https://dar-almanahel.com/fa/dashboard/";

describe("parseAppLocation", () => {
  it("strips locale prefix and trailing slash", () => {
    assert.deepEqual(parseAppLocation("/fa/dashboard/", HERE), {
      pathname: "/dashboard",
      search: "",
    });
    assert.deepEqual(parseAppLocation("/ar/dashboard/inventory", HERE), {
      pathname: "/dashboard/inventory",
      search: "",
    });
  });

  it("keeps query and ignores hash", () => {
    assert.deepEqual(
      parseAppLocation("/fa/dashboard/inventory/edit/?id=12#top", HERE),
      { pathname: "/dashboard/inventory/edit", search: "?id=12" }
    );
  });

  it("treats locale-less app paths as internal paths", () => {
    assert.equal(parseAppLocation("/dashboard/warehouse", HERE).pathname, "/dashboard/warehouse");
  });
});

describe("isInternalAppHref", () => {
  it("rejects hash, mail, tel, and other origins", () => {
    assert.equal(isInternalAppHref("#main", HERE), false);
    assert.equal(isInternalAppHref("mailto:a@b.c", HERE), false);
    assert.equal(isInternalAppHref("https://example.com/fa/dashboard", HERE), false);
  });

  it("accepts same-origin and root-relative paths", () => {
    assert.equal(isInternalAppHref("/dashboard/sales", HERE), true);
    assert.equal(isInternalAppHref("https://dar-almanahel.com/ar/dashboard/", HERE), true);
  });
});

describe("sameDestination", () => {
  it("treats trailing slash and locale prefix as the current page", () => {
    assert.equal(sameDestination("/dashboard", HERE), true);
    assert.equal(sameDestination("/fa/dashboard/", HERE), true);
    assert.equal(sameDestination("https://dar-almanahel.com/fa/dashboard", HERE), true);
  });

  it("treats a language switch as the same stripped path", () => {
    assert.equal(sameDestination("/ar/dashboard/", HERE), true);
  });

  it("treats query-only hops as a different destination", () => {
    assert.equal(sameDestination("/dashboard/inventory/edit?id=1", HERE), false);
    assert.equal(
      sameDestination(
        "/fa/dashboard/warehouse/log/?id=9",
        "https://dar-almanahel.com/fa/dashboard/warehouse/log/"
      ),
      false
    );
  });
});

describe("shouldStartNavigation", () => {
  it("starts on a real pathname hop", () => {
    assert.equal(shouldStartNavigation("/dashboard/inventory", HERE), true);
    assert.equal(shouldStartNavigation("/fa/dashboard/expenses/", HERE), true);
  });

  it("does not start on the current page, hash, or query-only hops", () => {
    assert.equal(shouldStartNavigation("/fa/dashboard/", HERE), false);
    assert.equal(shouldStartNavigation("/dashboard#x", HERE), false);
    assert.equal(
      shouldStartNavigation(
        "/dashboard/inventory/edit?id=2",
        "https://dar-almanahel.com/fa/dashboard/inventory/edit/?id=1"
      ),
      false
    );
  });

  it("does not start on replaceState to the same URL", () => {
    assert.equal(shouldStartNavigation("/fa/dashboard/?print=1", `${HERE}?print=1`), false);
    assert.equal(shouldStartNavigation(HERE, HERE), false);
  });
});
