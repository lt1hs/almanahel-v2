"use client";

import React, { useMemo } from "react";
import { Scan, Book as BookIcon, DollarSign, AlertCircle, Info, MapPin, FileText, ImageIcon, Upload, X } from "lucide-react";
import { Input } from "@/components/ui/Input";
import { cn } from "@/lib/utils";
import dynamic from "next/dynamic";
import { apiRequest, apiUpload } from "@/lib/api";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import {
    BOOK_CATEGORIES,
    BRANCH_STOCK_KEYS,
    BranchStockKey,
    defaultBranchStock,
    formatPriceDisplay,
    parseDecimalInput,
    parsePriceDigits,
    resolveBookCoverUrl,
    totalBranchStock,
} from "@/lib/bookFormUtils";

const ScannerModal = dynamic(
    () => import("./ScannerModal").then((m) => m.ScannerModal),
    { ssr: false }
);

interface BookFormProps {
    data: any;
    onChange: (data: any) => void;
    /** Intake: warehouse + Qom (+ Najaf when iraq-only). Full: all branches (edit). */
    stockFields?: "intake" | "full";
}

const fieldClass = "h-12 bg-white/40 border-white/60 focus:bg-white rounded-[10px] text-sm";
const priceClass = "h-12 bg-white/60 border-white focus:bg-white rounded-[10px] tabular-nums";

export function BookForm({ data, onChange, stockFields = "full" }: BookFormProps) {
    const { t, formatNumber } = useTranslation();
    const notify = useNotify();
    const [isScannerOpen, setIsScannerOpen] = React.useState(false);
    const [isUploadingCover, setIsUploadingCover] = React.useState(false);

    const coverPreview = data.coverImagePreview || resolveBookCoverUrl(data.coverImage);

    const branchStock = useMemo(
        () => ({ ...defaultBranchStock(), ...(data.branchStock || {}) }),
        [data.branchStock]
    );

    const visibleBranchKeys = useMemo((): BranchStockKey[] => {
        if (stockFields === "full") return [...BRANCH_STOCK_KEYS];
        const keys: BranchStockKey[] = ["warehouse", "qom"];
        if (data.iraqOnly) keys.push("najaf");
        return keys;
    }, [stockFields, data.iraqOnly]);

    const totalStock = useMemo(() => {
        if (stockFields === "intake") {
            return visibleBranchKeys.reduce(
                (sum, key) => sum + (parseInt(branchStock[key], 10) || 0),
                0
            );
        }
        return totalBranchStock(branchStock);
    }, [branchStock, stockFields, visibleBranchKeys]);

    const handleChange = (field: string, value: any) => {
        onChange({ ...data, [field]: value });
    };

    const handlePriceChange = (field: string, raw: string) => {
        handleChange(field, parsePriceDigits(raw));
    };

    const handleBranchStockChange = (key: BranchStockKey, raw: string) => {
        const digits = parsePriceDigits(raw);
        onChange({
            ...data,
            branchStock: { ...branchStock, [key]: digits },
        });
    };

    const handleCoverFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;

        const preview = URL.createObjectURL(file);
        onChange({ ...data, coverImagePreview: preview });
        setIsUploadingCover(true);

        try {
            const formData = new FormData();
            formData.append("image", file);
            const res = await apiUpload("/books/upload-cover", formData);
            onChange({
                ...data,
                coverImage: res.path,
                coverImagePreview: res.url || resolveBookCoverUrl(res.path) || preview,
            });
        } catch {
            notify.error("messages.errorOccurred");
            onChange({
                ...data,
                coverImagePreview: data.coverImage ? resolveBookCoverUrl(data.coverImage) : "",
            });
        } finally {
            setIsUploadingCover(false);
            e.target.value = "";
        }
    };

    const clearCover = () => {
        onChange({ ...data, coverImage: "", coverImagePreview: "" });
    };

    const branchLabel = (key: BranchStockKey) => t(`inventory.form.branchStock.${key}`);

    return (
        <div className="space-y-8">
            <div className="flex items-center gap-3 border-b border-ink/5 pb-4">
                <div className="p-2 bg-primary/5 rounded-lg">
                    <BookIcon className="w-4 h-4 text-primary" />
                </div>
                <div>
                    <h3 className="text-sm font-black font-vazirmatn text-ink">{t("inventory.form.identityTitle")}</h3>
                    <p className="text-[10px] text-ink/30 font-bold uppercase tracking-wider mt-0.5">{t("inventory.form.identityDesc")}</p>
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">
                        {t("inventory.bookTitle")} <span className="text-rose-500">*</span>
                    </label>
                    <Input
                        placeholder={t("inventory.form.titlePlaceholder")}
                        value={data.title || ""}
                        onChange={(e) => handleChange("title", e.target.value)}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.publicationYear")}</label>
                    <Input
                        type="text"
                        inputMode="numeric"
                        placeholder={t("inventory.form.publicationYearPlaceholder")}
                        value={data.publicationYear || ""}
                        onChange={(e) => handleChange("publicationYear", parsePriceDigits(e.target.value).slice(0, 4))}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.author")}</label>
                    <Input
                        placeholder={t("inventory.form.authorPlaceholder")}
                        value={data.author || ""}
                        onChange={(e) => handleChange("author", e.target.value)}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.publisher")}</label>
                    <Input
                        placeholder={t("inventory.form.publisherPlaceholder")}
                        value={data.publisher || ""}
                        onChange={(e) => handleChange("publisher", e.target.value)}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.size")}</label>
                    <Input
                        placeholder={t("inventory.form.sizePlaceholder")}
                        value={data.size || ""}
                        onChange={(e) => handleChange("size", e.target.value)}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.cover")}</label>
                    <Input
                        placeholder={t("inventory.form.coverPlaceholder")}
                        value={data.cover || ""}
                        onChange={(e) => handleChange("cover", e.target.value)}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.bookCategory")}</label>
                    <select
                        value={data.category || ""}
                        onChange={(e) => handleChange("category", e.target.value)}
                        className={cn(fieldClass, "w-full px-3 font-vazirmatn outline-none focus:ring-2 focus:ring-primary/15")}
                    >
                        <option value="">{t("inventory.form.categoryPlaceholder")}</option>
                        {BOOK_CATEGORIES.map((cat) => (
                            <option key={cat} value={cat}>{cat}</option>
                        ))}
                    </select>
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.volumeCount")}</label>
                    <Input
                        type="text"
                        inputMode="numeric"
                        placeholder="1"
                        value={data.volumeCount ?? ""}
                        onChange={(e) => handleChange("volumeCount", parsePriceDigits(e.target.value))}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.weight")}</label>
                    <Input
                        type="text"
                        inputMode="decimal"
                        placeholder={t("inventory.form.weightPlaceholder")}
                        value={data.weight ?? ""}
                        onChange={(e) => handleChange("weight", parseDecimalInput(e.target.value))}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.weightWithPackaging")}</label>
                    <Input
                        type="text"
                        inputMode="decimal"
                        placeholder={t("inventory.form.weightPlaceholder")}
                        value={data.weightWithPackaging ?? ""}
                        onChange={(e) => handleChange("weightWithPackaging", parseDecimalInput(e.target.value))}
                        className={fieldClass}
                    />
                </div>
                <div className="space-y-2 md:col-span-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1 flex items-center gap-1.5">
                        <ImageIcon className="w-3 h-3" />
                        {t("inventory.form.coverImage")}
                    </label>
                    <div className="flex flex-col sm:flex-row gap-4 p-4 bg-white/40 border border-white/60 rounded-[10px]">
                        <div className="relative shrink-0 w-28 h-36 rounded-[8px] border border-ink/10 bg-ink/[0.03] overflow-hidden flex items-center justify-center">
                            {coverPreview ? (
                                <>
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    <img src={coverPreview} alt="" className="w-full h-full object-cover" />
                                    <button
                                        type="button"
                                        onClick={clearCover}
                                        className="absolute top-1.5 end-1.5 p-1 rounded-full bg-black/50 text-white hover:bg-black/70 transition-colors"
                                        title={t("inventory.form.removeCoverImage")}
                                    >
                                        <X className="w-3 h-3" />
                                    </button>
                                </>
                            ) : (
                                <BookIcon className="w-8 h-8 text-ink/15" />
                            )}
                        </div>
                        <div className="flex flex-col justify-center gap-2 min-w-0">
                            <label className="inline-flex items-center justify-center gap-2 h-10 px-4 rounded-[8px] border border-primary/20 bg-primary/5 text-primary text-[11px] font-black cursor-pointer hover:bg-primary/10 transition-colors w-fit">
                                <Upload className="w-3.5 h-3.5" />
                                {isUploadingCover ? t("common.saving") : t("inventory.form.uploadCoverImage")}
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    disabled={isUploadingCover}
                                    onChange={handleCoverFile}
                                />
                            </label>
                            <p className="text-[10px] text-ink/35 font-vazirmatn">{t("inventory.form.coverImageHint")}</p>
                        </div>
                    </div>
                </div>
                <div className="space-y-2 md:col-span-2">
                    <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.isbn")}</label>
                    <div className="relative group">
                        <Input
                            placeholder="978-..."
                            value={data.isbn || ""}
                            onChange={(e) => handleChange("isbn", e.target.value)}
                            className={cn(fieldClass, "font-vazirmatn tabular-nums pr-12")}
                        />
                        <button
                            type="button"
                            onClick={() => setIsScannerOpen(true)}
                            className="absolute right-3 top-1/2 -translate-y-1/2 p-2 bg-primary/10 text-primary rounded-[5px] hover:bg-primary hover:text-white transition-all border border-primary/10 shadow-sm active:scale-90"
                            title={t("inventory.scanBarcode")}
                        >
                            <Scan className="w-4 h-4" />
                        </button>
                    </div>
                </div>
            </div>

            <div className="pt-2">
                <div className="flex items-center gap-3 border-b border-ink/5 pb-4 mb-6">
                    <div className="p-2 bg-accent/5 rounded-lg">
                        <DollarSign className="w-4 h-4 text-accent" />
                    </div>
                    <div>
                        <h3 className="text-sm font-black font-vazirmatn text-ink">{t("inventory.form.pricingTitle")}</h3>
                        <p className="text-[10px] text-ink/30 font-bold uppercase tracking-wider mt-0.5">{t("inventory.form.ownershipModel")}</p>
                    </div>
                </div>

                <div className="p-6 bg-white/40 rounded-[10px] border border-white/80 shadow-sm space-y-8">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div className="space-y-1">
                            <p className="text-[11px] font-black text-ink/60 font-vazirmatn">{t("inventory.form.purchaseType")}</p>
                        </div>
                        <div className="flex bg-ink/5 p-1 rounded-[10px] border border-ink/5 overflow-hidden w-full md:w-auto">
                            <button
                                type="button"
                                onClick={() => handleChange("type", "consignment")}
                                className={cn(
                                    "flex-1 md:flex-none px-8 py-2.5 rounded-[5px] text-[11px] font-black font-vazirmatn transition-all flex items-center justify-center gap-2",
                                    data.type === "consignment" ? "bg-white text-primary shadow-sm ring-1 ring-ink/5" : "text-ink/40 hover:text-ink/60"
                                )}
                            >
                                <Info className="w-3.5 h-3.5" />
                                {t("inventory.consignment")}
                            </button>
                            <button
                                type="button"
                                onClick={() => handleChange("type", "owned")}
                                className={cn(
                                    "flex-1 md:flex-none px-8 py-2.5 rounded-[5px] text-[11px] font-black font-vazirmatn transition-all flex items-center justify-center gap-2",
                                    data.type === "owned" ? "bg-white text-primary shadow-sm ring-1 ring-ink/5" : "text-ink/40 hover:text-ink/60"
                                )}
                            >
                                <AlertCircle className="w-3.5 h-3.5" />
                                {t("inventory.owned")}
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                        <div className="space-y-3 p-5 bg-ink/[0.02] border border-ink/5 rounded-[10px]">
                            <label className="text-[10px] font-black text-ink/50 uppercase tracking-widest px-1 block">{t("inventory.form.costPriceToman")}</label>
                            <Input
                                type="text"
                                inputMode="numeric"
                                placeholder={t("inventory.form.costPriceHint")}
                                value={formatPriceDisplay(data.costPriceToman)}
                                onChange={(e) => handlePriceChange("costPriceToman", e.target.value)}
                                className={cn(priceClass, "text-lg font-black text-ink")}
                            />
                        </div>
                        <div className="space-y-3 p-5 bg-ink/[0.02] border border-ink/5 rounded-[10px]">
                            <label className="text-[10px] font-black text-ink/50 uppercase tracking-widest px-1 block">{t("inventory.form.costPriceDinar")}</label>
                            <Input
                                type="text"
                                inputMode="numeric"
                                placeholder={t("inventory.form.costPriceHint")}
                                value={formatPriceDisplay(data.costPriceDinar)}
                                onChange={(e) => handlePriceChange("costPriceDinar", e.target.value)}
                                className={cn(priceClass, "text-lg font-black text-ink")}
                            />
                        </div>
                        <div className="space-y-3 p-5 bg-primary/[0.02] border border-primary/5 rounded-[10px]">
                            <label className="text-[10px] font-black text-primary/60 uppercase tracking-widest px-1 block">{t("inventory.form.priceTomanQom")}</label>
                            <Input
                                type="text"
                                inputMode="numeric"
                                placeholder="0"
                                value={formatPriceDisplay(data.priceTomanQom)}
                                onChange={(e) => handlePriceChange("priceTomanQom", e.target.value)}
                                className={cn(priceClass, "text-xl font-black text-primary")}
                            />
                        </div>
                        <div className="space-y-3 p-5 bg-primary/[0.02] border border-primary/5 rounded-[10px]">
                            <label className="text-[10px] font-black text-primary/60 uppercase tracking-widest px-1 block">{t("inventory.form.priceTomanMashhad")}</label>
                            <Input
                                type="text"
                                inputMode="numeric"
                                placeholder="0"
                                value={formatPriceDisplay(data.priceTomanMashhad)}
                                onChange={(e) => handlePriceChange("priceTomanMashhad", e.target.value)}
                                className={cn(priceClass, "text-xl font-black text-primary")}
                            />
                        </div>
                        <div className="space-y-3 p-5 bg-accent/[0.02] border border-accent/5 rounded-[10px] md:col-span-2">
                            <label className="text-[10px] font-black text-accent/60 uppercase tracking-widest px-1 block">{t("inventory.form.priceDinar")}</label>
                            <Input
                                type="text"
                                inputMode="numeric"
                                placeholder="0"
                                value={formatPriceDisplay(data.priceDinar)}
                                onChange={(e) => handlePriceChange("priceDinar", e.target.value)}
                                className={cn(priceClass, "text-xl font-black text-accent")}
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div className="pt-2">
                <div className="flex items-center gap-3 border-b border-ink/5 pb-4 mb-6">
                    <div className="p-2 bg-primary/5 rounded-lg">
                        <MapPin className="w-4 h-4 text-primary" />
                    </div>
                    <div>
                        <h3 className="text-sm font-black font-vazirmatn text-ink">
                            {stockFields === "intake"
                                ? t("inventory.form.intakeStockTitle")
                                : t("inventory.form.branchStockTitle")}
                        </h3>
                        <p className="text-[10px] text-ink/30 font-bold uppercase tracking-wider mt-0.5">
                            {stockFields === "intake"
                                ? t("inventory.form.intakeStockDesc")
                                : t("inventory.form.branchStockDesc")}
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6 p-6 bg-white/40 rounded-[10px] border border-white/80 shadow-sm">
                    {visibleBranchKeys.map((key) => (
                        <div key={key} className="space-y-2">
                            <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{branchLabel(key)}</label>
                            <Input
                                type="text"
                                inputMode="numeric"
                                placeholder="0"
                                value={branchStock[key] || ""}
                                onChange={(e) => handleBranchStockChange(key, e.target.value)}
                                className={cn(fieldClass, "text-lg font-black text-ink tabular-nums")}
                            />
                        </div>
                    ))}
                    <div className="space-y-2">
                        <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1">{t("inventory.form.totalStock")}</label>
                        <Input
                            readOnly
                            value={formatNumber(totalStock)}
                            className={cn(fieldClass, "text-lg font-black text-primary bg-primary/5 border-primary/10")}
                        />
                    </div>
                    <div className="space-y-2 md:col-span-2">
                        <label className="text-[10px] font-black text-ink/40 uppercase tracking-widest px-1 flex items-center gap-1.5">
                            <FileText className="w-3 h-3" />
                            {t("inventory.form.notes")}
                        </label>
                        <textarea
                            rows={3}
                            placeholder={t("inventory.form.notesPlaceholder")}
                            value={data.notes || ""}
                            onChange={(e) => handleChange("notes", e.target.value)}
                            className="w-full rounded-[10px] border border-white/60 bg-white/40 px-3 py-2.5 text-sm font-vazirmatn outline-none focus:bg-white focus:ring-2 focus:ring-primary/15 resize-y min-h-[88px]"
                        />
                    </div>
                </div>
            </div>

            <ScannerModal
                isOpen={isScannerOpen}
                onClose={() => setIsScannerOpen(false)}
                onDetected={async (code) => {
                    setIsScannerOpen(false);
                    try {
                        const book = await apiRequest(`/books/by-barcode/${encodeURIComponent(code)}`);
                        onChange({
                            ...data,
                            isbn: code,
                            title: book.title || data.title,
                            author: book.author || data.author,
                            publisher: book.publisher || data.publisher,
                        });
                        notify.success("toast.bookIdentified", { title: book.title });
                    } catch {
                        handleChange("isbn", code);
                        notify.info("toast.barcodeNewBook");
                    }
                }}
            />
        </div>
    );
}
