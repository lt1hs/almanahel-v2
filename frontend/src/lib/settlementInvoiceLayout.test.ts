import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
  defaultInvoiceDesign,
  isCustomInvoiceTemplate,
  keepProtectedFacts,
  mergeInvoiceTexts,
  normalizeInvoiceDesign,
  nudgeFieldPosition,
  officialInvoiceTemplateUrl,
  resolveInvoiceTemplate,
  applyOfficialLetterhead,
  invoiceLetterheadLang,
  buildDesignedInvoiceHtml,
  invoicePdfFileName,
  wrapTextLines,
} from "./settlementInvoiceLayout";

const generated = {
  bismillah: "",
  date: "تاریخ: 2026-09-01",
  number: "شماره: S-1",
  attachment: "فهرست تفصیلی فروش",
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

  it("uses official Persian and Arabic letterheads as the default templates", () => {
    assert.equal(officialInvoiceTemplateUrl("fa"), "/manahel-pr-invo.jpg");
    assert.equal(officialInvoiceTemplateUrl("ar"), "/manahel-ar-invo.jpg");
    assert.equal(defaultInvoiceDesign("fa").templateDataUrl, "/manahel-pr-invo.jpg");
    assert.equal(defaultInvoiceDesign("ar").templateDataUrl, "/manahel-ar-invo.jpg");
    assert.equal(resolveInvoiceTemplate(null, "ar"), "/manahel-ar-invo.jpg");
    assert.equal(resolveInvoiceTemplate("/manahel-pr-invo.jpg", "ar"), "/manahel-pr-invo.jpg");
    assert.equal(resolveInvoiceTemplate("/manahel-ar-invo.jpg", "fa"), "/manahel-ar-invo.jpg");
    assert.equal(resolveInvoiceTemplate("data:image/jpeg;base64,xx", "ar"), "data:image/jpeg;base64,xx");
    assert.equal(isCustomInvoiceTemplate("/manahel-pr-invo.jpg"), false);
    assert.equal(isCustomInvoiceTemplate("data:image/jpeg;base64,xx"), true);
    assert.ok(defaultInvoiceDesign().fields.some((field) => field.id === "attachment"));
    const date = defaultInvoiceDesign("fa").fields.find((field) => field.id === "date");
    const number = defaultInvoiceDesign("fa").fields.find((field) => field.id === "number");
    const attachment = defaultInvoiceDesign("fa").fields.find((field) => field.id === "attachment");
    assert.ok(date && number && attachment);
    assert.ok(date.x < 5);
    assert.ok(date.x + date.w >= 24, "box covers printed labels");
    assert.ok(Math.abs(date.y + date.h / 2 - 8.75) < 0.4);
    assert.ok(Math.abs(number.y + number.h / 2 - 10.78) < 0.4);
    assert.ok(Math.abs(attachment.y + attachment.h / 2 - 12.93) < 0.4);
  });

  it("lets the letterhead language be chosen without following the UI locale", () => {
    assert.equal(invoiceLetterheadLang("/manahel-pr-invo.jpg"), "fa");
    assert.equal(invoiceLetterheadLang("/manahel-ar-invo.jpg"), "ar");
    const arabic = applyOfficialLetterhead(defaultInvoiceDesign("fa"), "ar");
    assert.equal(arabic.templateDataUrl, "/manahel-ar-invo.jpg");
    const attachment = arabic.fields.find((field) => field.id === "attachment");
    const faAttachment = defaultInvoiceDesign("fa").fields.find((field) => field.id === "attachment");
    assert.ok(attachment && faAttachment);
    assert.notEqual(attachment.y, faAttachment.y);
  });

  it("nudges a selected field with arrow keys", () => {
    const date = defaultInvoiceDesign("fa").fields.find((field) => field.id === "date");
    assert.ok(date);
    const left = nudgeFieldPosition(date, "ArrowLeft");
    assert.ok(left.x < date.x);
    assert.equal(left.y, date.y);
    const up = nudgeFieldPosition(date, "ArrowUp", true);
    assert.ok(up.y < date.y - 0.5);
  });

  it("builds print HTML that keeps RTL shaping and the letterhead image", () => {
    const html = buildDesignedInvoiceHtml({
      design: defaultInvoiceDesign("fa"),
      texts: generated,
      title: "فاکتور",
      lang: "fa",
    });
    assert.match(html, /dir="rtl"/);
    assert.match(html, /<img class="letterhead"/);
    assert.match(html, /manahel-pr-invo\.jpg/);
    assert.match(html, /@page/);
    assert.match(html, /size:\s*A5/i);
    assert.match(html, /unicode-bidi:\s*isolate/);
    assert.equal(html.includes("html2canvas"), false);
    assert.equal(html.includes("<textarea"), false);
    assert.equal(html.includes("<button"), false);
    const attachment = html.match(/<div class="field"[^>]*>فهرست تفصیلی فروش<\/div>/);
    assert.ok(attachment);
    assert.match(attachment[0], /direction:rtl/);
    const date = html.match(/<div class="field"[^>]*>تاریخ: 2026-09-01<\/div>/);
    assert.ok(date);
    assert.match(date[0], /direction:ltr/);
  });

  it("wraps invoice field text to the box width without dropping words", () => {
    const lines = wrapTextLines("one two three four", 9, (value) => value.length);
    assert.deepEqual(lines, ["one two", "three", "four"]);
    assert.deepEqual(wrapTextLines("سلام\n\nخط بعد", 100, (value) => value.length), ["سلام", "", "خط بعد"]);
  });

  it("builds a safe PDF filename from the invoice title", () => {
    const name = invoicePdfFileName("فاکتور تسویه / SET-1");
    assert.match(name, /\.pdf$/i);
    assert.equal(name.includes("/"), false);
    assert.equal(name.includes("\\"), false);
  });
});
