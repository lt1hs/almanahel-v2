"use client";

import React, { useState, useEffect, useCallback } from "react";
import { Search, UserPlus, MapPin, Building2, Check, Loader2 } from "lucide-react";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Modal";
import { cn } from "@/lib/utils";
import { motion, AnimatePresence } from "framer-motion";
import { apiRequest, ApiError } from "@/lib/api";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import {
    parseSupplierAccountRow,
    selectionMatchingCanonicalSupplier,
    supplierAccountsUrl,
    type SupplierAccountSelection,
} from "@/lib/supplierAccountSelection";

const SUPPLIER_TYPES = ["publisher", "company", "individual"] as const;

interface SupplierSelectProps {
    onSelect: (account: SupplierAccountSelection) => void;
    selectedAccountId?: string | number;
    selectedCanonicalSupplierId?: string | number | null;
    branchId: number | null | undefined;
    compact?: boolean;
}

export function SupplierSelect({
    onSelect,
    selectedAccountId,
    selectedCanonicalSupplierId,
    branchId,
    compact = false,
}: SupplierSelectProps) {
    const { t } = useTranslation();
    const notify = useNotify();
    const [search, setSearch] = useState("");
    const [accounts, setAccounts] = useState<SupplierAccountSelection[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [newAccount, setNewAccount] = useState({ name: "", city: "", type: "publisher", phone: "" });

    const typeLabel = (type: string) => {
        const key = `suppliers.types.${type}`;
        const label = t(key);
        return label === key ? t("suppliers.types.publisher") : label;
    };

    const fetchAccounts = useCallback(async () => {
        if (!branchId) {
            setAccounts([]);
            setIsLoading(false);
            return;
        }
        setIsLoading(true);
        try {
            const data = await apiRequest(supplierAccountsUrl(branchId));
            const rows = Array.isArray(data) ? data : [];
            setAccounts(rows.map((row) => parseSupplierAccountRow(row, branchId)));
        } catch {
            setAccounts([]);
        } finally {
            setIsLoading(false);
        }
    }, [branchId]);

    useEffect(() => {
        fetchAccounts();
    }, [fetchAccounts]);

    useEffect(() => {
        if (selectedAccountId) return;
        const match = selectionMatchingCanonicalSupplier(accounts, Number(selectedCanonicalSupplierId));
        if (match) onSelect(match);
    }, [accounts, onSelect, selectedAccountId, selectedCanonicalSupplierId]);

    const isSelected = (account: SupplierAccountSelection) =>
        String(selectedAccountId ?? "") === String(account.accountId)
        || (
            !selectedAccountId
            && Number(selectedCanonicalSupplierId) === Number(account.canonicalSupplierId)
        );

    const filteredAccounts = accounts
        .filter((account) => account.name.toLowerCase().includes(search.toLowerCase()))
        .slice()
        .sort((a, b) => Number(isSelected(b)) - Number(isSelected(a)));

    const handleAddAccount = async () => {
        if (!newAccount.name || !branchId) return;
        setIsSaving(true);
        try {
            const created = await apiRequest("/supplier-accounts", {
                method: "POST",
                body: JSON.stringify({
                    branch_id: branchId,
                    display_name: newAccount.name,
                    phone: newAccount.phone || null,
                    address: newAccount.city || null,
                    city: newAccount.city || null,
                    type: newAccount.type,
                }),
            });
            const selection = parseSupplierAccountRow(created, branchId);
            await fetchAccounts();
            onSelect(selection);
            setIsModalOpen(false);
            setNewAccount({ name: "", city: "", type: "publisher", phone: "" });
        } catch (error) {
            const message = error instanceof ApiError ? error.message : t("toast.supplierSaveError");
            notify.rawError(message);
        } finally {
            setIsSaving(false);
        }
    };

    if (!branchId) {
        return (
            <div className={cn("text-center text-ink/30", compact ? "py-4" : "py-8")}>
                <p className="text-[11px] font-black">{t("inventory.selectBranchFirst")}</p>
            </div>
        );
    }

    return (
        <div className={cn(compact ? "space-y-3" : "space-y-6")}>
            <div className="flex flex-col sm:flex-row items-stretch sm:items-end gap-4 bg-white/30 p-4 rounded-[10px] border border-white/50 backdrop-blur-md">
                <div className="flex-1">
                    <label className="text-[10px] font-black text-ink/30 uppercase tracking-[0.2em] mb-2 block px-1">
                        {t("suppliers.search")}
                    </label>
                    <div className="relative group">
                        <Input
                            placeholder={t("suppliers.searchPlaceholder")}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="h-12 bg-white/50 border-white/80 focus:bg-white transition-all rounded-[10px] pr-12 text-sm"
                        />
                        <Search className="absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-ink/20 group-focus-within:text-primary transition-colors" />
                    </div>
                </div>

                <Button
                    variant="outline"
                    onClick={() => setIsModalOpen(true)}
                    className="h-12 border-dashed border-primary/30 rounded-[5px] text-[11px] font-black uppercase tracking-tight px-6 bg-primary/5 text-primary hover:bg-primary/10 active:scale-95 transition-all shadow-none flex items-center gap-2"
                >
                    <UserPlus className="w-4 h-4" />
                    {t("suppliers.add")}
                </Button>

                <Modal
                    isOpen={isModalOpen}
                    onClose={() => setIsModalOpen(false)}
                    title={t("suppliers.addModalTitle")}
                    description={t("suppliers.form.contactDesc")}
                >
                    <div className="space-y-4 font-ibm-plex-arabic">
                        <Input
                            label={t("suppliers.form.name")}
                            value={newAccount.name}
                            onChange={(e) => setNewAccount({ ...newAccount, name: e.target.value })}
                            className="h-11 rounded-xl border-ink/8 bg-parchment/25 font-ibm-plex-arabic focus:bg-white"
                            placeholder={t("suppliers.form.nameExample")}
                        />

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Input
                                label={t("suppliers.form.city")}
                                value={newAccount.city}
                                onChange={(e) => setNewAccount({ ...newAccount, city: e.target.value })}
                                className="h-11 rounded-xl border-ink/8 bg-parchment/25 font-ibm-plex-arabic focus:bg-white"
                                placeholder={t("suppliers.form.cityExample")}
                            />
                            <Input
                                label={t("suppliers.form.phone")}
                                value={newAccount.phone}
                                onChange={(e) => setNewAccount({ ...newAccount, phone: e.target.value })}
                                className="h-11 rounded-xl border-ink/8 bg-parchment/25 font-ibm-plex-arabic focus:bg-white"
                            />
                        </div>

                        <div className="space-y-2">
                            <label className="text-[11px] font-bold font-ibm-plex-arabic text-ink/45">
                                {t("suppliers.form.type")}
                            </label>
                            <div className="grid grid-cols-3 gap-2">
                                {SUPPLIER_TYPES.map((type) => {
                                    const selected = newAccount.type === type;
                                    return (
                                        <button
                                            key={type}
                                            type="button"
                                            onClick={() => setNewAccount({ ...newAccount, type })}
                                            className={cn(
                                                "h-10 rounded-xl border text-[12px] font-bold font-ibm-plex-arabic transition-all",
                                                selected
                                                    ? "border-primary/30 bg-primary/10 text-primary shadow-sm shadow-primary/10"
                                                    : "border-ink/8 bg-white/70 text-ink/50 hover:border-primary/20 hover:text-ink/70"
                                            )}
                                        >
                                            {typeLabel(type)}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="flex gap-2 border-t border-ink/5 pt-4">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setIsModalOpen(false)}
                                className="h-11 flex-1 rounded-xl text-[12px] font-bold font-ibm-plex-arabic"
                            >
                                {t("common.cancel")}
                            </Button>
                            <Button
                                onClick={handleAddAccount}
                                disabled={!newAccount.name || isSaving}
                                className="h-11 flex-[1.4] gap-2 rounded-xl text-[12px] font-bold font-ibm-plex-arabic shadow-lg shadow-primary/15"
                            >
                                {isSaving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
                                {t("suppliers.submit")}
                            </Button>
                        </div>
                    </div>
                </Modal>
            </div>

            {isLoading ? (
                <div className={cn("grid gap-4", compact ? "grid-cols-1" : "grid-cols-1 sm:grid-cols-2")}>
                    {Array.from({ length: compact ? 2 : 4 }).map((_, i) => (
                        <div key={i} className="h-24 bg-parchment/30 rounded-[10px] animate-pulse" />
                    ))}
                </div>
            ) : filteredAccounts.length === 0 ? (
                <div className={cn("text-center text-ink/30", compact ? "py-6" : "py-12")}>
                    <Building2 className="w-8 h-8 mx-auto mb-2 opacity-30" />
                    <p className="text-[11px] font-black">{t("suppliers.notFound")}</p>
                </div>
            ) : (
                <div className={cn(
                    "grid gap-4",
                    compact ? "grid-cols-1 max-h-44 overflow-y-auto pe-1" : "grid-cols-1 sm:grid-cols-2"
                )}>
                    <AnimatePresence mode="popLayout">
                        {filteredAccounts.map((account, index) => (
                            <motion.div
                                layout
                                key={account.accountId}
                                initial={{ opacity: 0, scale: 0.9 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0, scale: 0.9 }}
                                transition={{ duration: 0.3, delay: index * 0.05 }}
                            >
                                <Card
                                    onClick={() => onSelect(account)}
                                    className={cn(
                                        "cursor-pointer p-5 transition-all active:scale-[0.97] rounded-[10px] border relative overflow-hidden group/card",
                                        isSelected(account)
                                            ? "border-primary bg-primary/[0.03] shadow-[0_10px_30px_rgba(32,171,176,0.1)] ring-1 ring-primary/20"
                                            : "border-white/80 bg-white/40 hover:border-primary/30 hover:bg-white/60 shadow-sm"
                                    )}
                                >
                                    {isSelected(account) && (
                                        <motion.div
                                            layoutId="selected-indicator"
                                            className="absolute top-4 left-4 w-6 h-6 bg-primary text-white rounded-full flex items-center justify-center shadow-lg shadow-primary/20 z-10"
                                        >
                                            <Check className="w-3.5 h-3.5" />
                                        </motion.div>
                                    )}
                                    <div className="flex items-center gap-4">
                                        <div className={cn(
                                            "w-12 h-12 rounded-[10px] flex items-center justify-center transition-colors",
                                            isSelected(account)
                                                ? "bg-primary/10 text-primary"
                                                : "bg-ink/5 text-ink/30 group-hover/card:bg-primary/5 group-hover/card:text-primary/60"
                                        )}>
                                            <Building2 className="w-6 h-6" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <h4 className="font-vazirmatn font-black text-sm text-ink group-hover/card:text-primary transition-colors truncate">
                                                {account.name}
                                            </h4>
                                            <div className="flex items-center gap-2 mt-1">
                                                <span className="text-[9px] font-black uppercase tracking-tighter text-primary/50">
                                                    {typeLabel("publisher")}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </Card>
                            </motion.div>
                        ))}
                    </AnimatePresence>
                </div>
            )}
        </div>
    );
}

export type { SupplierAccountSelection };
