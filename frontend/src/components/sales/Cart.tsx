"use client";

import React from "react";
import { Trash2, Plus, Minus, Tag, BookOpen, ShoppingBag } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";

interface CartItem {
    id: string;
    title: string;
    price: number;
    quantity: number;
    stock?: number;
}

export interface PaymentDetails {
    customer_name: string;
    customer_phone: string;
    check_number: string;
    bank_name: string;
    payer_name: string;
    payer_phone: string;
    due_date: string;
}

interface CartProps {
    items: CartItem[];
    onUpdateQty: (id: string, delta: number) => void;
    onRemove: (id: string) => void;
    onCheckout: (payload: any) => Promise<void>;
    paymentMethod?: "cash" | "card" | "check" | "credit";
    currency?: "toman" | "dinar";
    paymentDetails?: PaymentDetails;
    onPaymentDetailsChange?: (details: PaymentDetails) => void;
}

const EMPTY_PAYMENT: PaymentDetails = {
    customer_name: "",
    customer_phone: "",
    check_number: "",
    bank_name: "",
    payer_name: "",
    payer_phone: "",
    due_date: "",
};

const fieldClass =
    "w-full h-8 rounded-lg border border-ink/10 bg-white px-2.5 text-[11px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary placeholder:text-ink/25";

export function Cart({
    items,
    onUpdateQty,
    onRemove,
    onCheckout,
    paymentMethod = "cash",
    currency = "toman",
    paymentDetails = EMPTY_PAYMENT,
    onPaymentDetailsChange,
}: CartProps) {
    const { t } = useTranslation();
    const [discount, setDiscount] = React.useState(0);
    const [useOverPrice, setUseOverPrice] = React.useState(false);
    const [overPrices, setOverPrices] = React.useState<Record<string, number>>({});
    const [isSubmitting, setIsSubmitting] = React.useState(false);
    const [localError, setLocalError] = React.useState<string | null>(null);

    const currencySymbol = currency === "dinar"
        ? t("common.currency.dinarSymbol")
        : t("common.currency.tomanSymbol");

    React.useEffect(() => {
        setLocalError(null);
    }, [paymentMethod]);

    React.useEffect(() => {
        if (items.length === 0) {
            setDiscount(0);
            setUseOverPrice(false);
            setOverPrices({});
            setLocalError(null);
        }
    }, [items.length]);

    const getActualPrice = (item: CartItem) => {
        if (useOverPrice && overPrices[item.id] && overPrices[item.id] > item.price) {
            return overPrices[item.id];
        }
        return item.price;
    };

    const subtotal = items.reduce((acc, item) => acc + getActualPrice(item) * item.quantity, 0);
    const discountAmount = subtotal * (discount / 100);
    const total = subtotal - discountAmount;

    const updatePayment = (field: keyof PaymentDetails, value: string) => {
        onPaymentDetailsChange?.({ ...paymentDetails, [field]: value });
    };

    const validatePayment = (): string | null => {
        if (paymentMethod === "check") {
            if (!paymentDetails.check_number.trim()) return t("sales.validation.checkNumberRequired");
            if (!paymentDetails.due_date) return t("sales.validation.checkDueRequired");
            if (!paymentDetails.payer_name.trim() && !paymentDetails.customer_name.trim()) {
                return t("sales.validation.payerNameRequired");
            }
        }
        if (paymentMethod === "credit") {
            if (!paymentDetails.customer_name.trim()) return t("sales.validation.creditCustomerRequired");
            if (!paymentDetails.due_date) return t("sales.validation.creditDueRequired");
        }
        return null;
    };

    const handleProcessCheckout = async () => {
        if (items.length === 0) return;

        const paymentError = validatePayment();
        if (paymentError) {
            setLocalError(paymentError);
            return;
        }
        setLocalError(null);

        setIsSubmitting(true);
        try {
            const perItemDiscount = items.length > 0 ? discountAmount / items.length : 0;
            const payload: Record<string, unknown> = {
                currency,
                payment_method: paymentMethod,
                items: items.map((i) => {
                    const actual = getActualPrice(i);
                    return {
                        book_id: parseInt(i.id),
                        quantity: i.quantity,
                        unit_price: i.price,
                        actual_price: actual,
                        discount: perItemDiscount / (i.quantity || 1),
                    };
                }),
            };

            if (paymentDetails.customer_name) payload.customer_name = paymentDetails.customer_name;
            if (paymentDetails.customer_phone) payload.customer_phone = paymentDetails.customer_phone;
            if (paymentDetails.due_date) payload.due_date = paymentDetails.due_date;

            if (paymentMethod === "check") {
                payload.check_number = paymentDetails.check_number;
                payload.bank_name = paymentDetails.bank_name || null;
                payload.payer_name = paymentDetails.payer_name || paymentDetails.customer_name;
                payload.payer_phone = paymentDetails.payer_phone || paymentDetails.customer_phone || null;
            }

            await onCheckout(payload);
        } finally {
            setIsSubmitting(false);
        }
    };

    const needsPaymentForm = paymentMethod === "check" || paymentMethod === "credit";

    return (
        <div className="flex flex-col h-full min-h-0 overflow-hidden">
            {/* Scrollable: cart lines + payment fields */}
            <div className="flex-1 min-h-0 overflow-y-auto scrollbar-hide p-3 space-y-2.5">
                <AnimatePresence initial={false}>
                    {items.map((item) => (
                        <motion.div
                            key={item.id}
                            layout
                            initial={{ opacity: 0, y: -8, scale: 0.97 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, x: 20, scale: 0.95, transition: { duration: 0.18 } }}
                            transition={{ type: "spring", stiffness: 400, damping: 30 }}
                            className="group bg-white/70 border border-white hover:border-primary/20 hover:bg-white rounded-xl p-3 transition-colors duration-200"
                        >
                            <div className="flex items-start justify-between gap-2 mb-2.5">
                                <button type="button" aria-label={t("common.delete")} onClick={() => onRemove(item.id)}
                                    className="mt-0.5 w-6 h-6 shrink-0 flex items-center justify-center rounded-lg text-ink/15 hover:text-rose-500 hover:bg-rose-50 transition-all duration-150">
                                    <Trash2 className="w-3 h-3" />
                                </button>
                                <div className="flex-1 min-w-0 text-end">
                                    <p className="font-vazirmatn font-black text-[12px] text-ink group-hover:text-primary transition-colors line-clamp-2 leading-snug">
                                        {item.title}
                                    </p>
                                    <div className="text-[10px] font-vazirmatn tabular-nums text-ink/35 mt-0.5">
                                        {item.price.toLocaleString()} {currencySymbol}
                                        {useOverPrice && (
                                            <input type="number" placeholder={t("sales.actualPrice")} title={t("sales.actualPrice")}
                                                className="mt-1 w-20 h-6 text-[9px] border border-primary/20 rounded px-1 outline-none"
                                                value={overPrices[item.id] ?? item.price}
                                                onChange={(e) => setOverPrices((p) => ({ ...p, [item.id]: Number(e.target.value) }))} />
                                        )}
                                    </div>
                                </div>
                                <div className="w-8 h-10 rounded-lg bg-parchment/60 border border-ink/5 flex items-center justify-center shrink-0">
                                    <BookOpen className="w-3.5 h-3.5 text-ink/20" />
                                </div>
                            </div>
                            <div className="flex items-center justify-between">
                                <div className="text-end">
                                    <span className="text-[13px] font-black font-vazirmatn tabular-nums text-ink/80">
                                        {(getActualPrice(item) * item.quantity).toLocaleString()}
                                    </span>
                                    <span className="text-[8px] font-vazirmatn text-ink/25 ms-0.5">{currencySymbol}</span>
                                </div>
                                <div className="flex items-center bg-white border border-ink/8 rounded-lg overflow-hidden shadow-sm">
                                    <button type="button" onClick={() => onUpdateQty(item.id, 1)}
                                        disabled={item.stock != null && item.quantity >= item.stock}
                                        className="w-7 h-7 flex items-center justify-center text-ink/30 hover:text-primary hover:bg-primary/5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
                                        <Plus className="w-3 h-3" />
                                    </button>
                                    <span className="w-7 text-center font-vazirmatn tabular-nums font-black text-[12px] text-ink border-x border-ink/8">{item.quantity}</span>
                                    <button type="button" onClick={() => onUpdateQty(item.id, -1)}
                                        className="w-7 h-7 flex items-center justify-center text-ink/30 hover:text-primary hover:bg-primary/5 transition-colors">
                                        <Minus className="w-3 h-3" />
                                    </button>
                                </div>
                            </div>
                        </motion.div>
                    ))}
                </AnimatePresence>

                {items.length === 0 && (
                    <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }}
                        className="flex flex-col items-center justify-center py-10 gap-3 text-ink/20">
                        <div className="w-14 h-14 rounded-2xl bg-parchment/60 border border-ink/5 flex items-center justify-center">
                            <ShoppingBag className="w-6 h-6" />
                        </div>
                        <p className="font-vazirmatn font-black text-[11px]">{t("sales.emptyCart")}</p>
                    </motion.div>
                )}

                {needsPaymentForm && (
                    <div className="space-y-2 p-2.5 rounded-xl bg-indigo-50/50 border border-indigo-100/60">
                        <p className="text-[9px] font-black text-indigo-600 uppercase tracking-widest px-0.5">
                            {paymentMethod === "check" ? t("sales.checkInfo") : t("sales.creditInfo")}
                        </p>

                        {paymentMethod === "check" ? (
                            <div className="grid grid-cols-2 gap-1.5">
                                <input
                                    type="text"
                                    placeholder={`${t("sales.checkNumber")} *`}
                                    value={paymentDetails.check_number}
                                    onChange={(e) => updatePayment("check_number", e.target.value)}
                                    className={cn(fieldClass, "col-span-2")}
                                />
                                <input
                                    type="text"
                                    placeholder={`${t("sales.payerName")} *`}
                                    value={paymentDetails.payer_name}
                                    onChange={(e) => updatePayment("payer_name", e.target.value)}
                                    className={fieldClass}
                                />
                                <input
                                    type="text"
                                    placeholder={t("sales.bankName")}
                                    value={paymentDetails.bank_name}
                                    onChange={(e) => updatePayment("bank_name", e.target.value)}
                                    className={fieldClass}
                                />
                                <input
                                    type="date"
                                    aria-label={`${t("sales.dueDate")} *`}
                                    title={`${t("sales.dueDate")} *`}
                                    value={paymentDetails.due_date}
                                    onChange={(e) => updatePayment("due_date", e.target.value)}
                                    className={fieldClass}
                                />
                                <input
                                    type="tel"
                                    placeholder={t("sales.customerPhone")}
                                    value={paymentDetails.customer_phone}
                                    onChange={(e) => updatePayment("customer_phone", e.target.value)}
                                    className={fieldClass}
                                />
                                <input
                                    type="text"
                                    placeholder={t("sales.customerName")}
                                    value={paymentDetails.customer_name}
                                    onChange={(e) => updatePayment("customer_name", e.target.value)}
                                    className={cn(fieldClass, "col-span-2")}
                                />
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-1.5">
                                <input
                                    type="text"
                                    placeholder={`${t("sales.customerName")} *`}
                                    value={paymentDetails.customer_name}
                                    onChange={(e) => updatePayment("customer_name", e.target.value)}
                                    className={cn(fieldClass, "col-span-2")}
                                />
                                <input
                                    type="tel"
                                    placeholder={t("sales.customerPhone")}
                                    value={paymentDetails.customer_phone}
                                    onChange={(e) => updatePayment("customer_phone", e.target.value)}
                                    className={fieldClass}
                                />
                                <input
                                    type="date"
                                    aria-label={`${t("sales.dueDate")} *`}
                                    title={`${t("sales.dueDate")} *`}
                                    value={paymentDetails.due_date}
                                    onChange={(e) => updatePayment("due_date", e.target.value)}
                                    className={fieldClass}
                                />
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Pinned footer: always visible — totals + submit */}
            <div className="shrink-0 border-t border-ink/[0.06] bg-white/80 backdrop-blur-md px-3.5 pt-3 pb-3.5 space-y-2.5">
                {localError && (
                    <p className="text-[10px] font-vazirmatn text-rose-600 bg-rose-50 border border-rose-100 rounded-lg px-2.5 py-1.5">{localError}</p>
                )}

                <div className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-1.5 text-ink/35 min-w-0">
                        <Tag className="w-3.5 h-3.5 text-accent/60 shrink-0" />
                        <span className="text-[10px] font-black font-vazirmatn truncate">{t("sales.totalDiscount")}</span>
                    </div>
                    <input type="number" min={0} max={100} value={discount}
                        onChange={(e) => setDiscount(Math.min(100, Math.max(0, Number(e.target.value))))}
                        className="w-12 h-7 bg-white border border-ink/10 rounded-lg px-1.5 text-[11px] font-black font-vazirmatn tabular-nums text-center outline-none focus:ring-1 focus:ring-accent/40" />
                </div>

                <div className="flex items-center justify-between gap-2">
                    <label className="flex items-center gap-1.5 text-ink/35 cursor-pointer select-none min-w-0">
                        <BookOpen className="w-3.5 h-3.5 text-primary/50 shrink-0" />
                        <span className="text-[10px] font-black font-vazirmatn truncate">{t("sales.coverPrice")}</span>
                    </label>
                    <button type="button" role="switch" aria-checked={useOverPrice}
                        onClick={() => setUseOverPrice((v) => !v)}
                        className={cn("w-9 h-5 rounded-full transition-colors relative shrink-0", useOverPrice ? "bg-primary" : "bg-ink/15")}>
                        <span className={cn("absolute top-0.5 w-4 h-4 bg-white rounded-full shadow-sm transition-all",
                            useOverPrice ? "start-[18px]" : "start-0.5")} />
                    </button>
                </div>

                <div className="space-y-1 pt-1.5 border-t border-ink/[0.06]">
                    <div className="flex justify-between items-center">
                        <span className="text-[10px] font-black text-ink/30">{t("sales.subtotal")}</span>
                        <span className="text-[11px] font-black font-vazirmatn tabular-nums text-ink/50">{subtotal.toLocaleString()} {currencySymbol}</span>
                    </div>
                    {discount > 0 && (
                        <div className="flex justify-between items-center">
                            <span className="text-[10px] font-black text-rose-400">{t("sales.discount")}</span>
                            <span className="text-[11px] font-black font-vazirmatn tabular-nums text-rose-500">-{discountAmount.toLocaleString()}</span>
                        </div>
                    )}
                    <div className="flex justify-between items-baseline gap-2 pt-1">
                        <span className="text-[11px] font-black text-ink shrink-0">{t("sales.payable")}</span>
                        <p className="text-[17px] font-black text-primary font-vazirmatn tabular-nums leading-none text-end">
                            {total.toLocaleString()}
                            <span className="text-[9px] text-primary/40 font-vazirmatn ms-1">{currencySymbol}</span>
                        </p>
                    </div>
                </div>

                <button type="button" disabled={items.length === 0 || isSubmitting} onClick={handleProcessCheckout}
                    className="w-full h-10 rounded-xl bg-primary hover:bg-primary/90 disabled:opacity-40 text-white shadow-lg shadow-primary/20 text-[12px] font-black font-vazirmatn transition-all active:scale-[0.98]">
                    {isSubmitting ? t("common.submitting") : t("sales.completeCheckout")}
                </button>
            </div>
        </div>
    );
}
