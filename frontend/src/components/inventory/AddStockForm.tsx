"use client";

import React, { useEffect, useMemo, useState } from "react";
import { AlertCircle, Info, PackagePlus } from "lucide-react";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { SupplierSelect } from "@/components/inventory/SupplierSelect";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { cn } from "@/lib/utils";
import { addStockIntake } from "@/lib/bookIntake";
import { apiRequest } from "@/lib/api";
import {
    BranchLike,
    branchSupportsDinar,
    formatPriceDisplay,
    isIraqStore,
    parsePriceDigits,
    priceBandForBranch,
    PriceBandValues,
} from "@/lib/bookFormUtils";

export type AddStockBook = {
    id: string;
    title: string;
    type: string;
    iraq_only?: boolean;
    priceBands: PriceBandValues;
    by_branch?: Array<{
        branch_id: number;
        price_toman?: number | null;
        price_dinar?: number | null;
        type?: string;
        supplier?: string;
    }>;
};

type BranchOption = BranchLike & { id: number; name: string };

function sellingForBranch(book: AddStockBook, branch: BranchLike): number {
    const row = book.by_branch?.find((b) => Number(b.branch_id) === Number(branch.id));
    const band = priceBandForBranch(branch);
    if (band === "najaf" || isIraqStore(branch)) {
        return Number(row?.price_dinar || book.priceBands.najaf || 0);
    }
    if (band === "mashhad") {
        return Number(row?.price_toman || book.priceBands.mashhad || book.priceBands.qom || 0);
    }
    return Number(row?.price_toman || book.priceBands.qom || book.priceBands.mashhad || 0);
}

export function AddStockForm({
    book,
    branches,
    defaultBranchId,
    initialSupplier,
    onCancel,
    onSuccess,
}: {
    book: AddStockBook;
    branches: BranchOption[];
    defaultBranchId?: number | "overview" | null;
    initialSupplier?: { id: number; name: string } | null;
    onCancel: () => void;
    onSuccess: () => void;
}) {
    const { t } = useTranslation();
    const notify = useNotify();
    const [type, setType] = useState<"owned" | "consignment">(
        book.type === "consignment" ? "consignment" : "owned"
    );
    const [supplier, setSupplier] = useState<{ id: number; name: string } | null>(initialSupplier ?? null);
    const [branchId, setBranchId] = useState<string>("");
    const [quantity, setQuantity] = useState("");
    const [costPrice, setCostPrice] = useState("");
    const [sellingPrice, setSellingPrice] = useState("");
    const [receivedAt, setReceivedAt] = useState(() => new Date().toISOString().split("T")[0]);
    const [notes, setNotes] = useState("");
    const [saving, setSaving] = useState(false);
    const [branchList, setBranchList] = useState<BranchOption[]>(branches);

    useEffect(() => {
        setBranchList(branches);
    }, [branches]);

    useEffect(() => {
        if (branches.length > 0) return;
        apiRequest("/branches")
            .then((list) => setBranchList(Array.isArray(list) ? list : []))
            .catch(() => setBranchList([]));
    }, [branches.length]);

    const eligibleBranches = useMemo(() => {
        if (book.iraq_only) {
            const iraq = branchList.filter((b) => isIraqStore(b));
            return iraq.length ? iraq : branchList;
        }
        return branchList;
    }, [book.iraq_only, branchList]);

    useEffect(() => {
        if (!eligibleBranches.length) return;
        const preferred =
            typeof defaultBranchId === "number"
                ? eligibleBranches.find((b) => b.id === defaultBranchId)
                : eligibleBranches.find((b) => b.type === "warehouse")
                    || eligibleBranches.find((b) => b.is_intake_hub)
                    || eligibleBranches[0];
        if (preferred?.id) setBranchId(String(preferred.id));
    }, [defaultBranchId, eligibleBranches]);

    const selectedBranch = eligibleBranches.find((b) => String(b.id) === branchId);

    useEffect(() => {
        if (!selectedBranch) return;
        const selling = sellingForBranch(book, selectedBranch);
        if (selling > 0) setSellingPrice(formatPriceDisplay(selling));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [book.id, selectedBranch?.id]);

    const currency: "toman" | "dinar" =
        selectedBranch && (
            isIraqStore(selectedBranch)
            || priceBandForBranch(selectedBranch) === "najaf"
            || (Boolean(branchSupportsDinar(selectedBranch)) && selectedBranch.supports_toman === false)
        )
            ? "dinar"
            : "toman";
    const currencyLabel = currency === "dinar"
        ? t("common.currency.dinarSymbol")
        : t("common.currency.tomanSymbol");

    const handleSubmit = async () => {
        const qty = parseInt(parsePriceDigits(quantity), 10) || 0;
        const selling = parseFloat(parsePriceDigits(sellingPrice)) || 0;
        const cost = parseFloat(parsePriceDigits(costPrice)) || 0;
        if (!selectedBranch?.id) {
            notify.error("inventory.addStockNeedBranch");
            return;
        }
        if (qty < 1) {
            notify.error("inventory.addStockNeedQty");
            return;
        }
        if (selling <= 0 && cost <= 0) {
            notify.error("inventory.addStockNeedPrice");
            return;
        }
        if (type === "consignment" && !supplier?.id) {
            notify.error("inventory.addStockNeedSupplier");
            return;
        }
        setSaving(true);
        try {
            await addStockIntake({
                bookId: Number(book.id),
                branchId: Number(selectedBranch.id),
                quantity: qty,
                type,
                supplierId: supplier?.id ?? null,
                currency,
                costPrice: cost,
                sellingPrice: selling || cost,
                notes: notes.trim() || null,
                receivedAt,
            });
            notify.success("inventory.addStockSuccess");
            onSuccess();
        } catch (error) {
            const message = error instanceof Error ? error.message : "";
            if (message === "supplier_required") {
                notify.error("inventory.addStockNeedSupplier");
            } else if (message) {
                notify.rawError(message);
            } else {
                notify.error("inventory.addStockError");
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <div className="space-y-6">
            <div className="rounded-2xl border border-white/80 bg-white/70 p-5">
                <p className="text-[10px] font-black text-ink/35 uppercase tracking-wider mb-1">
                    {t("inventory.bookTitle")}
                </p>
                <p className="text-lg font-black font-vazirmatn text-ink leading-snug">{book.title}</p>
                <p className="text-[12px] font-bold text-ink/45 mt-2 flex items-center gap-1.5">
                    <PackagePlus className="w-3.5 h-3.5 shrink-0" />
                    {t("inventory.addStockHint")}
                </p>
            </div>

            <div className="rounded-2xl border border-white/80 bg-white/70 p-5 md:p-6 space-y-6">
                <div>
                    <p className="text-[11px] font-black text-ink/60 font-vazirmatn mb-2">
                        {t("inventory.form.purchaseType")}
                    </p>
                    <div className="flex bg-ink/5 p-1 rounded-[10px] border border-ink/5 overflow-hidden">
                        <button
                            type="button"
                            onClick={() => setType("consignment")}
                            className={cn(
                                "flex-1 px-4 py-2.5 rounded-[5px] text-[11px] font-black font-vazirmatn transition-all flex items-center justify-center gap-2",
                                type === "consignment"
                                    ? "bg-white text-primary shadow-sm ring-1 ring-ink/5"
                                    : "text-ink/40 hover:text-ink/60"
                            )}
                        >
                            <Info className="w-3.5 h-3.5" />
                            {t("inventory.consignment")}
                        </button>
                        <button
                            type="button"
                            onClick={() => setType("owned")}
                            className={cn(
                                "flex-1 px-4 py-2.5 rounded-[5px] text-[11px] font-black font-vazirmatn transition-all flex items-center justify-center gap-2",
                                type === "owned"
                                    ? "bg-white text-primary shadow-sm ring-1 ring-ink/5"
                                    : "text-ink/40 hover:text-ink/60"
                            )}
                        >
                            <AlertCircle className="w-3.5 h-3.5" />
                            {t("inventory.owned")}
                        </button>
                    </div>
                </div>

                <div>
                    <p className="text-[11px] font-black text-ink/60 font-vazirmatn mb-2">
                        {t("inventory.supplier")}
                        {type === "consignment" ? "" : ` — ${t("inventory.addStockSupplierOptional")}`}
                    </p>
                    <SupplierSelect
                        selectedId={supplier?.id}
                        onSelect={(row) => setSupplier({ id: Number(row.id), name: row.name })}
                    />
                </div>

                <div>
                    <p className="text-[11px] font-black text-ink/60 font-vazirmatn mb-2">
                        {t("inventory.stockByBranch")}
                    </p>
                    <FilterSelect
                        value={branchId}
                        onChange={setBranchId}
                        options={eligibleBranches.map((b) => ({ value: String(b.id), label: b.name }))}
                        placeholder={t("inventory.form.branchStockTitle")}
                        defaultValue=""
                    />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <Input
                        label={t("common.quantity")}
                        value={quantity}
                        onChange={(e) => setQuantity(e.target.value.replace(/[^\d۰-۹٠-٩]/g, ""))}
                        inputMode="numeric"
                    />
                    <Input
                        label={t("inventory.addStockDate")}
                        type="date"
                        value={receivedAt}
                        onChange={(e) => setReceivedAt(e.target.value)}
                    />
                    <Input
                        label={`${t("inventory.costPrice")} (${currencyLabel})`}
                        value={costPrice}
                        onChange={(e) => setCostPrice(formatPriceDisplay(e.target.value))}
                        inputMode="numeric"
                        placeholder={t("inventory.form.costPriceHint")}
                    />
                    <Input
                        label={`${t("inventory.sellingPrice")} (${currencyLabel})`}
                        value={sellingPrice}
                        onChange={(e) => setSellingPrice(formatPriceDisplay(e.target.value))}
                        inputMode="numeric"
                    />
                </div>

                <Input
                    label={t("inventory.form.notes")}
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    placeholder={t("inventory.form.notesPlaceholder")}
                />

                <div className="flex flex-col-reverse sm:flex-row gap-2 pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        className="flex-1 h-12 text-[12px] font-black"
                        onClick={onCancel}
                        disabled={saving}
                    >
                        {t("common.cancel")}
                    </Button>
                    <Button
                        type="button"
                        className="flex-1 h-12 text-[12px] font-black"
                        onClick={handleSubmit}
                        isLoading={saving}
                    >
                        {t("inventory.addStock")}
                    </Button>
                </div>
            </div>
        </div>
    );
}
