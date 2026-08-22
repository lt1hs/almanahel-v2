"use client";

import React, { useCallback, useEffect, useMemo, useState } from "react";
import {
  Users,
  Plus,
  Search,
  Mail,
  Phone,
  MapPin,
  Edit,
  Trash2,
  X,
  Building2,
  Power,
  Package,
} from "lucide-react";
import { Link } from "@/i18n/routing";
import { Card, CardContent } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";
import { useAuth } from "@/contexts/AuthContext";
import { supplierAccountsUrl } from "@/lib/supplierAccountSelection";

type SupplierType = "publisher" | "company" | "individual";
type SupplierStatus = "active" | "inactive";

interface Supplier {
  id: number;
  name: string;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  city?: string | null;
  type?: SupplierType;
  status?: SupplierStatus;
  consignment_receipts_count?: number;
  inventories_count?: number;
  settlements_count?: number;
}

const EMPTY_FORM = {
  name: "",
  email: "",
  phone: "",
  address: "",
  city: "",
  type: "publisher" as SupplierType,
};

export default function SuppliersPage() {
  const { t, formatNumber } = useTranslation();
  const notify = useNotify();
  const { user } = useAuth();
  const isAdminCatalog = user?.role === "admin" || user?.role === "super_admin";
  const branchId = user?.branch_id ? Number(user.branch_id) : null;

  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [searchQuery, setSearchQuery] = useState("");
  const [typeFilter, setTypeFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all");
  const [showForm, setShowForm] = useState(false);
  const [editingSupplier, setEditingSupplier] = useState<Supplier | null>(null);
  const [formData, setFormData] = useState(EMPTY_FORM);
  const [isSaving, setIsSaving] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Supplier | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);

  const fetchSuppliers = useCallback(async () => {
    setIsLoading(true);
    try {
      if (isAdminCatalog) {
        const data = await apiRequest("/suppliers");
        setSuppliers(Array.isArray(data) ? data : []);
      } else if (branchId) {
        const data = await apiRequest(supplierAccountsUrl(branchId));
        setSuppliers(
          (Array.isArray(data) ? data : []).map((row: Record<string, unknown>) => ({
            id: Number(row.id),
            name: String(row.display_name ?? row.name ?? `#${row.id}`),
            email: (row.email as string | null | undefined) ?? null,
            phone: (row.phone as string | null | undefined) ?? null,
            address: (row.address as string | null | undefined) ?? null,
            city: (row.city as string | null | undefined) ?? null,
            type: (row.type as SupplierType | undefined) ?? "publisher",
            status: (row.status as SupplierStatus | undefined) ?? "active",
          }))
        );
      } else {
        setSuppliers([]);
      }
    } catch (error) {
      console.error("Failed to fetch suppliers:", error);
    } finally {
      setIsLoading(false);
    }
  }, [branchId, isAdminCatalog]);

  useEffect(() => {
    fetchSuppliers();
  }, [fetchSuppliers]);

  const openCreate = () => {
    setEditingSupplier(null);
    setFormData(EMPTY_FORM);
    setShowForm(true);
  };

  const handleEdit = (supplier: Supplier) => {
    setEditingSupplier(supplier);
    setFormData({
      name: supplier.name || "",
      email: supplier.email || "",
      phone: supplier.phone || "",
      address: supplier.address || "",
      city: supplier.city || "",
      type: (supplier.type as SupplierType) || "publisher",
    });
    setShowForm(true);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.name.trim()) {
      notify.error("toast.requiredFields");
      return;
    }

    setIsSaving(true);
    try {
      if (isAdminCatalog) {
        const method = editingSupplier ? "PUT" : "POST";
        const url = editingSupplier ? `/suppliers/${editingSupplier.id}` : "/suppliers";
        await apiRequest(url, {
          method,
          body: JSON.stringify({
            name: formData.name.trim(),
            email: formData.email.trim() || null,
            phone: formData.phone.trim() || null,
            address: formData.address.trim() || null,
            city: formData.city.trim() || null,
            type: formData.type,
          }),
        });
      } else if (branchId) {
        const payload = {
          branch_id: branchId,
          display_name: formData.name.trim(),
          email: formData.email.trim() || null,
          phone: formData.phone.trim() || null,
          address: formData.address.trim() || null,
          city: formData.city.trim() || null,
          type: formData.type,
        };
        if (editingSupplier) {
          await apiRequest(`/supplier-accounts/${editingSupplier.id}`, {
            method: "PUT",
            body: JSON.stringify(payload),
          });
        } else {
          await apiRequest("/supplier-accounts", {
            method: "POST",
            body: JSON.stringify(payload),
          });
        }
      } else {
        notify.error("toast.supplierSaveError");
        return;
      }
      setShowForm(false);
      setEditingSupplier(null);
      setFormData(EMPTY_FORM);
      await fetchSuppliers();
      notify.success(editingSupplier ? "toast.supplierUpdated" : "toast.supplierCreated");
    } catch (error) {
      console.error("Failed to save supplier:", error);
      notify.error("toast.supplierSaveError");
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!pendingDelete) return;
    setIsDeleting(true);
    try {
      await apiRequest(`/suppliers/${pendingDelete.id}`, { method: "DELETE" });
      setPendingDelete(null);
      await fetchSuppliers();
      notify.success("toast.supplierDeleted");
    } catch (error: unknown) {
      const message = error instanceof Error ? error.message : "";
      if (message.includes("غیرفعال") || message.toLowerCase().includes("deactiv") || message.includes("سابقه")) {
        notify.error("toast.supplierHasHistory");
      } else {
        notify.rawError(message || t("toast.supplierDeleteError"));
      }
    } finally {
      setIsDeleting(false);
    }
  };

  const handleToggleStatus = async (supplier: Supplier) => {
    const nextStatus: SupplierStatus = supplier.status === "inactive" ? "active" : "inactive";
    try {
      if (isAdminCatalog) {
        await apiRequest(`/suppliers/${supplier.id}`, {
          method: "PUT",
          body: JSON.stringify({ status: nextStatus }),
        });
      } else {
        await apiRequest(`/supplier-accounts/${supplier.id}`, {
          method: "PUT",
          body: JSON.stringify({ status: nextStatus }),
        });
      }
      await fetchSuppliers();
      notify.success(nextStatus === "active" ? "toast.supplierActivated" : "toast.supplierDeactivated");
    } catch (error) {
      console.error("Failed to toggle supplier status:", error);
      notify.error("toast.supplierSaveError");
    }
  };

  const filteredSuppliers = useMemo(() => {
    const q = searchQuery.trim().toLowerCase();
    return suppliers.filter((s) => {
      const matchesSearch =
        !q ||
        s.name?.toLowerCase().includes(q) ||
        s.city?.toLowerCase().includes(q) ||
        s.phone?.toLowerCase().includes(q) ||
        s.email?.toLowerCase().includes(q) ||
        s.address?.toLowerCase().includes(q);
      const matchesType = typeFilter === "all" || s.type === typeFilter;
      const matchesStatus =
        statusFilter === "all" || (s.status || "active") === statusFilter;
      return matchesSearch && matchesType && matchesStatus;
    });
  }, [suppliers, searchQuery, typeFilter, statusFilter]);

  const stats = useMemo(() => {
    const active = suppliers.filter((s) => (s.status || "active") === "active").length;
    return {
      total: suppliers.length,
      active,
      inactive: suppliers.length - active,
    };
  }, [suppliers]);

  const typeLabel = (type?: string) => {
    if (type === "publisher") return t("suppliers.types.publisher");
    if (type === "company") return t("suppliers.types.company");
    return t("suppliers.types.individual");
  };

  const typeOptions = [
    { value: "all", label: t("suppliers.allTypes") },
    { value: "publisher", label: t("suppliers.types.publisher") },
    { value: "company", label: t("suppliers.types.company") },
    { value: "individual", label: t("suppliers.types.individual") },
  ];

  const statusOptions = [
    { value: "all", label: t("suppliers.allStatuses") },
    { value: "active", label: t("suppliers.statusActive") },
    { value: "inactive", label: t("suppliers.statusInactive") },
  ];

  return (
    <div className="space-y-6 pb-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-xl font-black font-vazirmatn text-ink">{t("suppliers.title")}</h1>
          <p className="mt-0.5 text-[10px] font-bold uppercase tracking-widest text-ink/35">
            {t("suppliers.subtitle")}
          </p>
        </div>
        <Button
          variant="primary"
          size="sm"
          className="h-9 rounded-xl px-4 text-[11px] shadow-lg shadow-primary/10"
          onClick={openCreate}
        >
          <Plus className="ms-1.5 h-3.5 w-3.5" />
          {t("suppliers.addModalTitle")}
        </Button>
      </div>

      <div className="grid grid-cols-3 gap-3">
        {[
          { label: t("suppliers.statsTotal"), value: stats.total, icon: Users },
          { label: t("suppliers.statsActive"), value: stats.active, icon: Building2 },
          { label: t("suppliers.statsInactive"), value: stats.inactive, icon: Power },
        ].map((item) => (
          <Card key={item.label} className="rounded-2xl border border-white/70 bg-white/70 shadow-sm">
            <CardContent className="flex items-center gap-3 p-4">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/5 text-primary">
                <item.icon className="h-4 w-4" />
              </div>
              <div>
                <p className="text-[10px] font-bold text-ink/40">{item.label}</p>
                <p className="text-lg font-black text-ink">{formatNumber(item.value)}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <div className="relative flex-1">
          <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto h-3.5 w-3.5 text-ink/20" />
          <input
            type="text"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            placeholder={t("suppliers.searchPlaceholder")}
            className="h-11 w-full rounded-xl border border-ink/5 bg-white/50 pe-3 ps-9 text-[12px] font-vazirmatn outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
          />
        </div>
        <FilterSelect
          value={typeFilter}
          onChange={setTypeFilter}
          options={typeOptions}
          className="sm:min-w-[150px]"
        />
        <FilterSelect
          value={statusFilter}
          onChange={setStatusFilter}
          options={statusOptions}
          className="sm:min-w-[150px]"
        />
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        {isLoading ? (
          Array.from({ length: 6 }).map((_, i) => (
            <div key={i} className="h-48 animate-pulse rounded-2xl border border-ink/5 bg-white/50" />
          ))
        ) : filteredSuppliers.length === 0 ? (
          <div className="col-span-full flex flex-col items-center justify-center rounded-2xl border border-dashed border-ink/10 bg-white/40 px-6 py-16 text-center">
            <Building2 className="mb-3 h-10 w-10 text-ink/20" />
            <p className="text-sm font-black text-ink/60">{t("suppliers.notFound")}</p>
            <p className="mt-1 max-w-sm text-[11px] text-ink/35">{t("suppliers.emptyHint")}</p>
            <Button className="mt-4 h-9 rounded-xl text-[11px]" onClick={openCreate}>
              <Plus className="ms-1.5 h-3.5 w-3.5" />
              {t("suppliers.add")}
            </Button>
          </div>
        ) : (
          filteredSuppliers.map((supplier) => {
            const isInactive = supplier.status === "inactive";
            const receiptCount = supplier.consignment_receipts_count ?? 0;

            return (
              <Card
                key={supplier.id}
                className={cn(
                  "group overflow-hidden rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl transition-all hover:shadow-lg",
                  isInactive && "opacity-70"
                )}
              >
                <CardContent className="p-5">
                  <div className="mb-4 flex items-start justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-3">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-primary/10 bg-primary/5 text-primary transition-all group-hover:bg-primary group-hover:text-white">
                        <Building2 className="h-5 w-5" />
                      </div>
                      <div className="min-w-0">
                        <h3 className="truncate text-[14px] font-black font-vazirmatn text-ink">
                          {supplier.name}
                        </h3>
                        <p className="mt-0.5 text-[10px] text-ink/35">{typeLabel(supplier.type)}</p>
                      </div>
                    </div>

                    <div className="flex shrink-0 items-center gap-0.5">
                      <button
                        type="button"
                        onClick={() => handleEdit(supplier)}
                        className="rounded-lg p-1.5 text-ink/25 transition-all hover:bg-ink/5 hover:text-primary"
                        aria-label={t("common.edit")}
                        title={t("common.edit")}
                      >
                        <Edit className="h-3.5 w-3.5" />
                      </button>
                      <button
                        type="button"
                        onClick={() => handleToggleStatus(supplier)}
                        className="rounded-lg p-1.5 text-ink/25 transition-all hover:bg-ink/5 hover:text-ink"
                        aria-label={isInactive ? t("suppliers.activate") : t("suppliers.deactivate")}
                        title={isInactive ? t("suppliers.activate") : t("suppliers.deactivate")}
                      >
                        <Power className="h-3.5 w-3.5" />
                      </button>
                      {isAdminCatalog && (
                      <button
                        type="button"
                        onClick={() => setPendingDelete(supplier)}
                        className="rounded-lg p-1.5 text-ink/25 transition-all hover:bg-rose-50 hover:text-rose-600"
                        aria-label={t("suppliers.delete")}
                        title={t("suppliers.delete")}
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                      </button>
                      )}
                    </div>
                  </div>

                  <div className="space-y-2.5">
                    <div className="flex items-center gap-2 text-[11px] text-ink/50">
                      <Phone className="h-3 w-3 shrink-0" />
                      <span className="font-vazirmatn tabular-nums">{supplier.phone || "—"}</span>
                    </div>
                    <div className="flex items-center gap-2 text-[11px] text-ink/50">
                      <Mail className="h-3 w-3 shrink-0" />
                      <span className="truncate">{supplier.email || "—"}</span>
                    </div>
                    <div className="flex items-center gap-2 text-[11px] text-ink/50">
                      <MapPin className="h-3 w-3 shrink-0" />
                      <span className="truncate">
                        {[supplier.city, supplier.address].filter(Boolean).join(" · ") || "—"}
                      </span>
                    </div>
                    {receiptCount > 0 && (
                      <div className="flex items-center gap-2 text-[11px] text-ink/50">
                        <Package className="h-3 w-3 shrink-0" />
                        <span>{t("suppliers.receiptsCount", { count: formatNumber(receiptCount) })}</span>
                      </div>
                    )}
                  </div>

                  <div className="mt-5 flex items-center justify-between border-t border-ink/5 pt-4">
                    <Badge
                      variant="outline"
                      className={cn(
                        "text-[9px]",
                        isInactive
                          ? "border-ink/10 bg-ink/5 text-ink/45"
                          : "border-emerald-100 bg-emerald-50 text-emerald-600"
                      )}
                    >
                      {isInactive ? t("suppliers.statusInactive") : t("suppliers.statusActive")}
                    </Badge>
                    <Link
                      href={
                        isAdminCatalog
                          ? `/dashboard/consignment?supplier_id=${supplier.id}`
                          : `/dashboard/consignment?supplier_account_id=${supplier.id}`
                      }
                      className="rounded-lg px-2 py-1 text-[10px] font-bold text-primary transition-colors hover:bg-primary/5"
                    >
                      {t("suppliers.viewTransactions")}
                    </Link>
                  </div>
                </CardContent>
              </Card>
            );
          })
        )}
      </div>

      {showForm && (
        <div
          className="fixed inset-0 z-[60] flex items-center justify-center bg-ink/30 p-4 backdrop-blur-sm"
          onClick={(e) => e.target === e.currentTarget && !isSaving && setShowForm(false)}
        >
          <div className="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl">
            <form onSubmit={handleSubmit}>
              <div className="flex items-center justify-between border-b border-ink/5 bg-indigo-50/30 px-6 py-5">
                <div>
                  <h2 className="text-[15px] font-black font-vazirmatn text-ink">
                    {editingSupplier ? t("suppliers.form.editTitle") : t("suppliers.addModalTitle")}
                  </h2>
                  <p className="mt-0.5 text-[10px] text-ink/35">{t("suppliers.form.contactDesc")}</p>
                </div>
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={() => setShowForm(false)}
                  className="rounded-xl p-2 hover:bg-ink/5"
                >
                  <X className="h-4 w-4 text-ink/40" />
                </button>
              </div>

              <div className="max-h-[60vh] space-y-4 overflow-y-auto p-6 scrollbar-hide">
                <div className="space-y-1.5">
                  <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                    {t("suppliers.form.name")}
                  </label>
                  <input
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder={t("suppliers.form.nameExample")}
                    className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                      {t("suppliers.form.type")}
                    </label>
                    <select
                      value={formData.type}
                      onChange={(e) =>
                        setFormData({ ...formData, type: e.target.value as SupplierType })
                      }
                      className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                    >
                      <option value="publisher">{t("suppliers.types.publisher")}</option>
                      <option value="company">{t("suppliers.types.company")}</option>
                      <option value="individual">{t("suppliers.types.individual")}</option>
                    </select>
                  </div>
                  <div className="space-y-1.5">
                    <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                      {t("suppliers.form.city")}
                    </label>
                    <input
                      value={formData.city}
                      onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                      placeholder={t("suppliers.form.cityExample")}
                      className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-1.5">
                    <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                      {t("common.phone")}
                    </label>
                    <input
                      value={formData.phone}
                      onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                      className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-left text-[12px] font-vazirmatn tabular-nums outline-none focus:ring-1 focus:ring-primary"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                      {t("common.email")}
                    </label>
                    <input
                      type="email"
                      value={formData.email}
                      onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                      className="h-10 w-full rounded-xl border border-ink/10 bg-parchment/20 px-3 text-[12px] outline-none focus:ring-1 focus:ring-primary"
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <label className="text-[10px] font-black uppercase tracking-widest text-ink/40">
                    {t("common.address")}
                  </label>
                  <textarea
                    rows={2}
                    value={formData.address}
                    onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                    className="w-full resize-none rounded-xl border border-ink/10 bg-parchment/20 px-3 py-2 text-[12px] font-vazirmatn outline-none focus:ring-1 focus:ring-primary"
                  />
                </div>
              </div>

              <div className="flex gap-2 border-t border-ink/5 bg-parchment/20 px-6 py-4">
                <Button
                  type="button"
                  variant="ghost"
                  className="h-10 flex-1 rounded-xl"
                  disabled={isSaving}
                  onClick={() => setShowForm(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  type="submit"
                  isLoading={isSaving}
                  className="h-10 flex-1 rounded-xl font-black shadow-lg shadow-primary/15"
                >
                  {editingSupplier ? t("common.update") : t("suppliers.submit")}
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {pendingDelete && (
        <div
          className="fixed inset-0 z-[70] flex items-center justify-center bg-ink/40 p-4 backdrop-blur-sm"
          onClick={(e) => e.target === e.currentTarget && !isDeleting && setPendingDelete(null)}
        >
          <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
            <h3 className="text-[15px] font-black text-ink">{t("suppliers.confirmDeleteTitle")}</h3>
            <p className="mt-2 text-[12px] leading-6 text-ink/55">
              {t("suppliers.confirmDelete", { name: pendingDelete.name })}
            </p>
            <div className="mt-6 flex gap-2">
              <Button
                type="button"
                variant="ghost"
                className="h-10 flex-1 rounded-xl"
                disabled={isDeleting}
                onClick={() => setPendingDelete(null)}
              >
                {t("common.cancel")}
              </Button>
              <Button
                type="button"
                variant="danger"
                isLoading={isDeleting}
                className="h-10 flex-1 rounded-xl"
                onClick={handleDelete}
              >
                {t("suppliers.delete")}
              </Button>
            </div>
            <button
              type="button"
              disabled={isDeleting}
              className="mt-3 w-full rounded-xl py-2 text-[11px] font-bold text-ink/45 hover:bg-ink/5 hover:text-ink"
              onClick={() => {
                const target = pendingDelete;
                setPendingDelete(null);
                handleToggleStatus(target);
              }}
            >
              {t("suppliers.deactivate")}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
