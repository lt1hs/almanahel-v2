import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { localDateTimeInputValue, localDateTimeToIso } from "./localDateTime";

describe("localDateTime", () => {
  it("formats datetime-local from local wall clock, not UTC", () => {
    const value = localDateTimeInputValue(new Date(2026, 8, 7, 19, 5, 0));
    assert.equal(value, "2026-09-07T19:05");
  });

  it("converts a datetime-local value to an instant with timezone", () => {
    const iso = localDateTimeToIso("2026-09-07T19:00");
    assert.match(iso, /Z$/);
    const roundTrip = new Date(iso);
    assert.equal(roundTrip.getFullYear(), 2026);
    assert.equal(roundTrip.getMonth(), 8);
    assert.equal(roundTrip.getDate(), 7);
    assert.equal(roundTrip.getHours(), 19);
    assert.equal(roundTrip.getMinutes(), 0);
  });

  it("keeps an already-zoned ISO string as the same instant", () => {
    const iso = localDateTimeToIso("2026-09-07T16:00:00.000Z");
    assert.equal(new Date(iso).toISOString(), "2026-09-07T16:00:00.000Z");
  });
});
