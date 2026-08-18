"use client";

import React, { useState, useEffect, useCallback } from "react";
import { Search, UserPlus, MapPin, Building2, Check, Loader2 } from "lucide-react";
import { Input } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Modal } from "@/components/ui/Modal";
import { cn } from "@/lib/utils";
import { motion, AnimatePresence } from "framer-motion";
import { apiRequest } from "@/lib/api";
import { useTranslation } from "@/hooks/useTranslation";

const SUPPLIER_TYPES = ["publisher", "company", "individual"] as const;

interface SupplierSelectProps {
    onSelect: (supplier: any) => void;
    selectedId?: string | number;
    compact?: boolean;
}

export function SupplierSelect({ onSelect, selectedId, compact = false }: SupplierSelectProps) {
    const { t } = useTranslation();
    const [search, setSearch] = useState("");
    const [suppliers, setSuppliers] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [newSupplier, setNewSupplier] = useState({ name: "", city: "", type: "publisher", phone: "" });

    const typeLabel = (type: string) => {
        const key = `suppliers.types.${type}`;
        const label = t(key);
        return label === key ? t("suppliers.types.publisher") : label;
    };

    const fetchSuppliers = useCallback(async () => {
        setIsLoading(true);
        try {
            const data = await apiRequest("/suppliers");
            setSuppliers(Array.isArray(data) ? data : []);
        } catch {
            setSuppliers([]);
        } finally {
            setIsLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchSuppliers();
    }, [fetchSuppliers]);

    const filteredSuppliers = suppliers.filter((s) =>
        s.name?.toLowerCase().includes(search.toLowerCase()) ||
        (s.city || s.address || "").toLowerCase().includes(search.toLowerCase())
    );

    const handleAddSupplier = async () => {
        if (!newSupplier.name) return;
        setIsSaving(true);
        try {
            const created = await apiRequest("/suppliers", {
                method: "POST",
                body: JSON.stringify({
                    name: newSupplier.name,
                    phone: newSupplier.phone || null,
                    address: newSupplier.city || null,
                    type: newSupplier.type,
                }),
            });
            await fetchSuppliers();
            onSelect(created);
            setIsModalOpen(false);
            setNewSupplier({ name: "", city: "", type: "publisher", phone: "" });
        } catch (error) {
            console.error("Failed to create supplier:", error);
        } finally {
            setIsSaving(false);
        }
    };

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

                <Modal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} title={t("suppliers.addModalTitle")}>
                    <div className="space-y-4">
                        <Input
                            label={t("suppliers.form.name")}
                            value={newSupplier.name}
                            onChange={(e) => setNewSupplier({ ...newSupplier, name: e.target.value })}
                            className="h-12 bg-ink/[0.02] border-ink/5 focus:bg-white"
                            placeholder={t("suppliers.form.nameExample")}
                        />
                        <Input
                            label={t("suppliers.form.city")}
                            value={newSupplier.city}
                            onChange={(e) => setNewSupplier({ ...newSupplier, city: e.target.value })}
                            className="h-12 bg-ink/[0.02] border-ink/5 focus:bg-white"
                            placeholder={t("suppliers.form.cityExample")}
                        />
                        <Input
                            label={t("suppliers.form.phone")}
                            value={newSupplier.phone}
                            onChange={(e) => setNewSupplier({ ...newSupplier, phone: e.target.value })}
                            className="h-12 bg-ink/[0.02] border-ink/5 focus:bg-white"
                        />
                        <div className="space-y-1.5">
                            <label className="text-sm font-medium font-vazirmatn text-ink/70 mr-1">{t("suppliers.form.type")}</label>
                            <select
                                value={newSupplier.type}
                                onChange={(e) => setNewSupplier({ ...newSupplier, type: e.target.value })}
                                className="w-full h-12 rounded-[7px] border border-ink/10 bg-ink/[0.02] px-4 text-sm font-vazirmatn focus:outline-none focus:ring-2 focus:ring-primary focus:bg-white transition-all appearance-none cursor-pointer"
                            >
                                {SUPPLIER_TYPES.map((type) => (
                                    <option key={type} value={type}>{typeLabel(type)}</option>
                                ))}
                            </select>
                        </div>
                        <Button
                            onClick={handleAddSupplier}
                            disabled={!newSupplier.name || isSaving}
                            className="w-full h-14 bg-primary text-white font-black text-sm rounded-[10px] hover:bg-primary/90 shadow-lg shadow-primary/20 mt-2 transition-all active:scale-[0.98] flex items-center justify-center gap-2"
                        >
                            {isSaving ? <Loader2 className="w-5 h-5 animate-spin" /> : <Check className="w-5 h-5" />}
                            {t("suppliers.submit")}
                        </Button>
                    </div>
                </Modal>
            </div>

            {isLoading ? (
                <div className={cn("grid gap-4", compact ? "grid-cols-1" : "grid-cols-1 sm:grid-cols-2")}>
                    {Array.from({ length: compact ? 2 : 4 }).map((_, i) => (
                        <div key={i} className="h-24 bg-parchment/30 rounded-[10px] animate-pulse" />
                    ))}
                </div>
            ) : filteredSuppliers.length === 0 ? (
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
                        {filteredSuppliers.map((supplier, index) => (
                            <motion.div
                                layout
                                key={supplier.id}
                                initial={{ opacity: 0, scale: 0.9 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0, scale: 0.9 }}
                                transition={{ duration: 0.3, delay: index * 0.05 }}
                            >
                                <Card
                                    onClick={() => onSelect(supplier)}
                                    className={cn(
                                        "cursor-pointer p-5 transition-all active:scale-[0.97] rounded-[10px] border relative overflow-hidden group/card",
                                        String(selectedId) === String(supplier.id)
                                            ? "border-primary bg-primary/[0.03] shadow-[0_10px_30px_rgba(32,171,176,0.1)] ring-1 ring-primary/20"
                                            : "border-white/80 bg-white/40 hover:border-primary/30 hover:bg-white/60 shadow-sm"
                                    )}
                                >
                                    {String(selectedId) === String(supplier.id) && (
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
                                            String(selectedId) === String(supplier.id)
                                                ? "bg-primary/10 text-primary"
                                                : "bg-ink/5 text-ink/30 group-hover/card:bg-primary/5 group-hover/card:text-primary/60"
                                        )}>
                                            <Building2 className="w-6 h-6" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <h4 className="font-vazirmatn font-black text-sm text-ink group-hover/card:text-primary transition-colors truncate">
                                                {supplier.name}
                                            </h4>
                                            <div className="flex items-center gap-2 mt-1">
                                                <span className="flex items-center gap-1 text-[10px] text-ink/40 font-bold uppercase tracking-wider">
                                                    <MapPin className="w-3 h-3 opacity-50" />
                                                    {supplier.city || supplier.address || "—"}
                                                </span>
                                                <span className="w-1 h-1 rounded-full bg-ink/10" />
                                                <span className="text-[9px] font-black uppercase tracking-tighter text-primary/50">
                                                    {typeLabel(supplier.type)}
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
