"use client";

import React, { Suspense, useCallback, useEffect, useMemo, useState } from "react";
import { AlertTriangle, Loader2 } from "lucide-react";
import { useSearchParams } from "next/navigation";
import { useRouter } from "@/i18n/routing";
import { Button } from "@/components/ui/Button";
import {
    BranchOption,
    WarehouseLogForm,
    WarehouseLogFormData,
    WarehouseLogRecord,
} from "@/components/warehouse/WarehouseLogForm";
import { useAuth } from "@/contexts/AuthContext";
import { useNotify } from "@/hooks/useNotify";
import { useTranslation } from "@/hooks/useTranslation";
import { apiRequest } from "@/lib/api";

export const dynamic = "force-static";

function dedupeBranches(list: BranchOption[]): BranchOption[] {
    const seen = new Set<string>();
    return list.filter((b) => {
        const key = (b.name || "").trim().toLowerCase();
        if (!key || seen.has(key)) return false;
        seen.add(key);
        return true;
    });
}

function WarehouseLogPageContent() {
    const { t } = useTranslation();
    const notify = useNotify();
    const router = useRouter();
    const { user } = useAuth();
    const searchParams = useSearchParams();

    const directionParam = searchParams.get("direction");
    const logId = searchParams.get("id") || "";
    const branchParam = searchParams.get("branch");

    const [direction, setDirection] = useState<"in" | "out">(
        directionParam === "out" ? "out" : "in"
    );
    const [branch, setBranch] = useState<BranchOption | null>(null);
    const [branches, setBranches] = useState<BranchOption[]>([]);
    const [inventory, setInventory] = useState<any[]>([]);
    const [editLog, setEditLog] = useState<WarehouseLogRecord | null>(null);
    const [isLoading, setIsLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const isAdmin = user?.role === "super_admin" || user?.role === "admin";

    const pickBranch = useCallback((list: BranchOption[], preferredId?: number | null) => {
        if (preferredId) {
            const match = list.find((b) => b.id === preferredId);
            if (match) return match;
        }
        if (user?.role === "warehouse_staff" && user.branch?.id) {
            return list.find((b) => b.id === user.branch!.id)
                ?? list.find((b) => b.type === "warehouse")
                ?? null;
        }
        if (user?.branch?.id && !isAdmin) {
            return list.find((b) => b.id === user.branch!.id) ?? null;
        }
        return list.find((b) => b.type === "warehouse") ?? list[0] ?? null;
    }, [user, isAdmin]);

    useEffect(() => {
        let cancelled = false;

        (async () => {
            setIsLoading(true);
            setError(null);
            try {
                const branchListRaw = await apiRequest("/branches");
                const list = dedupeBranches(
                    (Array.isArray(branchListRaw) ? branchListRaw : []).map((b: any) => ({
                        id: Number(b.id),
                        name: String(b.name || ""),
                        city: String(b.city || ""),
                        type: String(b.type || "store"),
                        status: b.status,
                    }))
                );
                if (cancelled) return;
                setBranches(list);

                if (logId) {
                    const log = await apiRequest(`/warehouse/logs/${logId}`) as WarehouseLogRecord & {
                        branch_id?: number;
                        related_transfer_id?: number | null;
                    };
                    if (cancelled) return;
                    if (log.related_transfer_id) {
                        notify.error("warehouse.errors.notEditable");
                        router.replace("/dashboard/warehouse");
                        return;
                    }
                    const resolved = pickBranch(list, Number(log.branch_id) || null);
                    if (!resolved) {
                        setError(t("warehouse.errors.noWarehouse"));
                        return;
                    }
                    const inv = await apiRequest(`/warehouse/${resolved.id}/inventory`);
                    if (cancelled) return;
                    setBranch(resolved);
                    setInventory(Array.isArray(inv) ? inv : []);
                    setDirection(log.direction === "out" ? "out" : "in");
                    setEditLog(log);
                } else {
                    const preferred = branchParam ? parseInt(branchParam, 10) : null;
                    const resolved = pickBranch(list, Number.isFinite(preferred) ? preferred : null);
                    if (!resolved) {
                        setError(t("warehouse.errors.noWarehouse"));
                        return;
                    }
                    const inv = await apiRequest(`/warehouse/${resolved.id}/inventory`);
                    if (cancelled) return;
                    setBranch(resolved);
                    setInventory(Array.isArray(inv) ? inv : []);
                    setDirection(directionParam === "out" ? "out" : "in");
                    setEditLog(null);
                }
            } catch (err) {
                console.error(err);
                if (!cancelled) {
                    setError((err as Error).message || t("warehouse.loadError"));
                }
            } finally {
                if (!cancelled) setIsLoading(false);
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [logId, branchParam, directionParam, pickBranch, router, notify, t]);

    const goBack = () => router.push("/dashboard/warehouse");

    const handleSubmit = async (form: WarehouseLogFormData) => {
        if (!branch || !form.book_id || !form.handler_name) {
            notify.error("toast.requiredFields");
            return;
        }
        const qty = parseInt(form.quantity, 10);
        if (!editLog) {
            const selectedOutItem = inventory.find((item) => String(item.book?.id) === form.book_id);
            if (direction === "out" && selectedOutItem && qty > selectedOutItem.quantity) {
                notify.error("warehouse.errors.insufficientStock");
                return;
            }
        }

        setIsSubmitting(true);
        try {
            if (!editLog && form.as_transfer && form.to_branch_id) {
                await apiRequest("/transfers", {
                    method: "POST",
                    body: JSON.stringify({
                        from_branch_id: branch.id,
                        to_branch_id: parseInt(form.to_branch_id, 10),
                        items: [{ book_id: parseInt(form.book_id, 10), quantity: qty }],
                        notes: form.notes
                            || t("warehouse.purpose.transferNoteAuto", {
                                name: form.handler_name,
                                phone: form.handler_phone || "—",
                            }),
                    }),
                });
                notify.success("warehouse.purpose.transferCreated");
                router.push("/dashboard/distribution");
                return;
            }

            const payload = {
                quantity: qty,
                handler_name: form.handler_name,
                handler_phone: form.handler_phone || null,
                reason: form.reason,
                notes: form.notes || null,
                log_date: form.log_date,
            };

            if (editLog) {
                await apiRequest(`/warehouse/logs/${editLog.id}`, {
                    method: "PUT",
                    body: JSON.stringify(payload),
                });
                notify.success("messages.savedSuccessfully");
            } else {
                await apiRequest("/warehouse/logs", {
                    method: "POST",
                    body: JSON.stringify({
                        branch_id: branch.id,
                        book_id: parseInt(form.book_id, 10),
                        direction,
                        ...payload,
                    }),
                });
                notify.success("toast.warehouseLogSuccess");
            }
            goBack();
        } catch (err) {
            if ((err as Error).message) notify.rawError((err as Error).message);
            else notify.error(editLog ? "messages.errorOccurred" : "toast.warehouseLogError");
        } finally {
            setIsSubmitting(false);
        }
    };

    const titleHint = useMemo(
        () => (direction === "out" ? t("warehouse.modal.titleOut") : t("warehouse.modal.titleIn")),
        [direction, t]
    );

    if (isLoading) {
        return (
            <div className="flex flex-col items-center justify-center py-28 gap-3 text-ink/35">
                <Loader2 className="w-7 h-7 animate-spin text-primary" />
                <p className="text-[12px] font-black font-vazirmatn">{t("common.loading")}</p>
                <p className="text-[10px] text-ink/25">{titleHint}</p>
            </div>
        );
    }

    if (error || !branch) {
        return (
            <div className="flex flex-col items-center justify-center py-24 gap-4">
                <AlertTriangle className="w-10 h-10 text-rose-400" />
                <p className="text-ink/50 font-vazirmatn text-sm">{error || t("warehouse.errors.noWarehouse")}</p>
                <Button onClick={goBack}>{t("common.back")}</Button>
            </div>
        );
    }

    return (
        <WarehouseLogForm
            direction={direction}
            inventory={inventory}
            branches={branches}
            isSubmitting={isSubmitting}
            editLog={editLog}
            branchName={branch.name}
            branchId={branch.id}
            onBack={goBack}
            onSubmit={handleSubmit}
        />
    );
}

export default function WarehouseLogPage() {
    return (
        <Suspense
            fallback={
                <div className="flex items-center justify-center py-28">
                    <Loader2 className="w-7 h-7 animate-spin text-primary" />
                </div>
            }
        >
            <WarehouseLogPageContent />
        </Suspense>
    );
}
