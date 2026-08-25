import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { PAGE_READY_TIMEOUT_MS, createPageReadyLatch } from "./pageReady.js";

describe("createPageReadyLatch", () => {
  it("starts unrevealed and latches on markReady", () => {
    const latch = createPageReadyLatch();
    assert.equal(latch.revealed, false);
    latch.markReady();
    assert.equal(latch.revealed, true);
    latch.markReady();
    assert.equal(latch.revealed, true);
  });

  it("ignores later unready signals — there is no markUnready", () => {
    const latch = createPageReadyLatch();
    latch.markReady();
    assert.equal("markUnready" in latch, false);
    assert.equal(latch.revealed, true);
  });

  it("tracks whether a page registered usePageReady", () => {
    const latch = createPageReadyLatch();
    assert.equal(latch.subscriberCount, 0);
    const off = latch.register();
    assert.equal(latch.subscriberCount, 1);
    off();
    assert.equal(latch.subscriberCount, 0);
  });
});

describe("PAGE_READY_TIMEOUT_MS", () => {
  it("is the single 8s safety for gate and nav bar", () => {
    assert.equal(PAGE_READY_TIMEOUT_MS, 8000);
  });
});
