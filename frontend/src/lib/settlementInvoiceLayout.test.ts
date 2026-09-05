import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { defaultInvoiceDesign, normalizeInvoiceDesign } from "./settlementInvoiceLayout";

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
});
