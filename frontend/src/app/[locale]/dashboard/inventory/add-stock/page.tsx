"use client";

import React, { Suspense, useCallback, useEffect, useState } from "react";
import { motion } from "framer-motion";
import { ArrowRight, PackagePlus } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { RequireRole } from "@/components/auth/RequireRole";
import { AddStockForm, type AddStockBook } from "@/components/inventory/AddStockForm";
import { useSearchParams } from "next/navigation";
import { useRouter } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";
import { apiRequest } from "@/lib/api";
import { collectPriceBands } from "@/lib/bookFormUtils";
import { useInvalidateNotifications } from "@/hooks/useNotificationInbox";

export const dynamic = "force-static";

function mapBook(data: any): AddStockBook {
    const inventories = Array.isArray(data.inventories) ? data.inventories : [];
    const by_branch = inventories.map((inv: any) => ({
        branch_id: Number(inv.branch_id),
        price_toman: inv.price_toman,
        price_dinar: inv.price_dinar,
        type: inv.type,
        supplier: inv.supplier?.name,
        branch_type: inv.branch?.type,
        branch_city: inv.branch?.city,
        branch_name: inv.branch?.name,
        is_iraq_store: inv.branch?.is_iraq_store,
        supports_dinar: inv.branch?.supports_dinar,
        supports_toman: inv.branch?.supports_toman,
    }));

    return {
        id: String(data.id),
        title: data.title,
        type: inventories[0]?.type || "owned",
        iraq_only: Boolean(data.iraq_only),
        priceBands: collectPriceBands(by_branch),
        by_branch,
    };
}

function AddStockContent() {
    const { t } = useTranslation();
    const router = useRouter();
    const searchParams = useSearchParams();
    const bookId = searchParams.get("id") || "";
    const branchParam = searchParams.get("branch");
    const defaultBranchId =
        branchParam && Number.isFinite(Number(branchParam)) ? Number(branchParam) : "overview";
    const invalidateNotifications = useInvalidateNotifications();

    const [book, setBook] = useState<AddStockBook | null>(null);
    const [branches, setBranches] = useState<any[]>([]);
    const [supplier, setSupplier] = useState<{ id: number; name: string } | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const fetchData = useCallback(async () => {
        if (!bookId) {
            setError("missing");
            setIsLoading(false);
            return;
        }
        setIsLoading(true);
        try {
            const [data, branchList] = await Promise.all([
                apiRequest(`/books/${bookId}`),
                apiRequest("/branches"),
            ]);
            const mapped = mapBook(data);
            setBook(mapped);
            setBranches(Array.isArray(branchList) ? branchList : []);
            const firstInv = Array.isArray(data.inventories) ? data.inventories[0] : null;
            if (firstInv?.supplier?.id) {
                setSupplier({ id: Number(firstInv.supplier.id), name: firstInv.supplier.name });
            }
        } catch (err) {
            setError(err instanceof Error ? err.message : "error");
            setBook(null);
        } finally {
            setIsLoading(false);
        }
    }, [bookId]);

    useEffect(() => {
        void fetchData();
    }, [fetchData]);

    const goBack = () => router.push("/dashboard/inventory");

    if (isLoading) {
        return (
            <div className="max-w-5xl mx-auto space-y-4 pb-10">
                <div className="h-28 rounded-2xl bg-parchment/30 animate-pulse" />
                <div className="h-40 rounded-2xl bg-parchment/20 animate-pulse" />
                <div className="h-96 rounded-2xl bg-parchment/20 animate-pulse" />
            </div>
        );
    }

    if (!book) {
        return (
            <div className="text-center py-20">
                <p className="text-ink/40 font-black">{error || t("toast.bookNotFound")}</p>
                <Button className="mt-4" onClick={goBack}>{t("common.back")}</Button>
            </div>
        );
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            className="max-w-5xl mx-auto space-y-5 pb-12"
        >
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-10 w-10 p-0 rounded-xl border border-ink/5 bg-white/70 shrink-0"
                        onClick={goBack}
                    >
                        <ArrowRight className="w-4 h-4" />
                    </Button>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2">
                            <div className="w-10 h-10 rounded-[10px] bg-white border border-white shadow-sm flex items-center justify-center shrink-0">
                                <PackagePlus className="w-5 h-5 text-primary" />
                            </div>
                            <div className="min-w-0">
                                <h1 className="text-xl font-black font-vazirmatn text-ink truncate">
                                    {t("inventory.addStock")}
                                </h1>
                                <p className="text-[10px] text-ink/35 mt-0.5 font-bold">
                                    {t("inventory.referenceId")}: {book.id}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <AddStockForm
                book={book}
                branches={branches}
                defaultBranchId={defaultBranchId}
                initialSupplier={supplier}
                onCancel={goBack}
                onSuccess={() => {
                    invalidateNotifications();
                    goBack();
                }}
            />
        </motion.div>
    );
}

export default function AddStockPage() {
    return (
        <RequireRole roles={["super_admin", "admin"]}>
            <Suspense
                fallback={
                    <div className="max-w-5xl mx-auto space-y-4 pb-10">
                        <div className="h-28 rounded-2xl bg-parchment/30 animate-pulse" />
                        <div className="h-40 rounded-2xl bg-parchment/20 animate-pulse" />
                        <div className="h-96 rounded-2xl bg-parchment/20 animate-pulse" />
                    </div>
                }
            >
                <AddStockContent />
            </Suspense>
        </RequireRole>
    );
}
