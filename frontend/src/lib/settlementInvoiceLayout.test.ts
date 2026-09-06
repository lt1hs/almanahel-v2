import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
  defaultInvoiceDesign,
  keepProtectedFacts,
  mergeInvoiceTexts,
  normalizeInvoiceDesign,
} from "./settlementInvoiceLayout";

const generated = {
  bismillah: "",
  date: "تاریخ: 2026-09-01",
  number: "شماره: S-1",
  recipient: "جناب ناشر",
  greeting: "سلام",
  body: "از تاریخ 2026-08-01 تا 2026-08-31 به مبلغ ۱۰۰۰ تومان تسویه شد.",
  closing: "",
  signIssuer: "",
  signReceiver: "",
  contact: "",
};

const facts = ["2026-09-01", "2026-08-31", "2026-08-01", "۱۰۰۰"];

describe("settlement invoice layout", () => {
  it("keeps all default fields when raw payload is partial", () => {
    const next = normalizeInvoiceDesign({
      version: 1,
      templateDataUrl: "data:image/jpeg;base64,xx",
      fields: [{ id: "body", x: 20, y: 40, w: 70, h: 18, fontSize: 14, align: "center" }],
    });
    assert.equal(next.fields.length, defaultInvoiceDesign().fields.length);
    const body = next.fields.find((field) => field.id === "body");
    assert.equal(body?.x, 20);
    assert.equal(body?.h, 18);
    assert.equal(body?.align, "center");
    assert.equal(next.templateDataUrl, "data:image/jpeg;base64,xx");
  });

  it("keeps wording edits but rejects saved text that changes date or amount", () => {
    const merged = mergeInvoiceTexts(
      generated,
      {
        date: "تاریخ صدور: 2026-09-01",
        body: "حساب شما از تاریخ 2026-08-01 تا 2026-08-31 به مبلغ ۱۰۰۰ تومان بسته شد.",
        greeting: "درود",
      },
      facts
    );
    assert.equal(merged.date, "تاریخ صدور: 2026-09-01");
    assert.match(merged.body, /بسته شد/);
    assert.equal(merged.greeting, "درود");

    const rejected = mergeInvoiceTexts(
      generated,
      {
        date: "تاریخ: 1999-01-01",
        body: "به مبلغ ۹۹۹ تومان تسویه شد.",
      },
      facts
    );
    assert.equal(rejected.date, generated.date);
    assert.equal(rejected.body, generated.body);
  });

  it("blocks typing that removes amount or dates but allows surrounding edits", () => {
    const body = generated.body;
    assert.equal(
      keepProtectedFacts("حساب از تاریخ 2026-08-01 تا 2026-08-31 به مبلغ ۱۰۰۰ بسته شد.", body, facts),
      "حساب از تاریخ 2026-08-01 تا 2026-08-31 به مبلغ ۱۰۰۰ بسته شد."
    );
    assert.equal(keepProtectedFacts("به مبلغ ۹۹۹ تومان", body, facts), body);
    assert.equal(keepProtectedFacts("بدون تاریخ", body, facts), body);
  });
});
