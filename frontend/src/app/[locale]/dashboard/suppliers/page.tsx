"use client";

import { usePageReady } from "@/components/NavigationProgress";
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
import { apiRequest, ApiError } from "@/lib/api";
import { cn } from "@/lib/utils";
import { useAuth } from "@/contexts/AuthContext";
import { supplierAccountsUrl } from "@/lib/supplierAccountSelection";

type SupplierType = "publisher" | "company" | "individual";
type SupplierStatus = "active" | "inactive";

interface BranchOption {
  id: number;
  name: string;
  city?: string | null;
}

type AdminViewMode = "canonical" | "branch";

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
  isBranchAccount?: boolean;
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
  usePageReady(!isLoading);
  const [searchQuery, setSearchQuery] = useState("");
  const [typeFilter, setTypeFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all");
  const [showForm, setShowForm] = useState(false);
  const [editingSupplier, setEditingSupplier] = useState<Supplier | null>(null);
  const [formData, setFormData] = useState(EMPTY_FORM);
  const [isSaving, setIsSaving] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Supplier | null>(null);
  const [isDeleting, setIsDeleting] = useState(false);
  const [adminViewMode, setAdminViewMode] = useState<AdminViewMode>("canonical");
  const [adminBranchId, setAdminBranchId] = useState<number | null>(null);
  const [branches, setBranches] = useState<BranchOption[]>([]);

  const activeBranchId = isAdminCatalog
    ? adminViewMode === "branch"
      ? adminBranchId
      : null
    : branchId;

  const usingBranchAccounts = isAdminCatalog ? adminViewMode === "branch" : Boolean(branchId);

  useEffect(() => {
    if (!isAdminCatalog) return;
    apiRequest("/branches?lite=1")
      .then((data) => {
        const list = (Array.isArray(data) ? data : []) as BranchOption[];
        setBranches(list);
        setAdminBranchId((current) => current ?? (list[0]?.id != null ? Number(list[0].id) : null));
      })
      .catch(() => setBranches([]));
  }, [isAdminCatalog]);

  const mapBranchAccountRow = (row: Record<string, unknown>): Supplier => ({
    id: Number(row.id),
    name: String(row.display_name ?? row.name ?? `#${row.id}`),
    email: (row.email as string | null | undefined) ?? null,
    phone: (row.phone as string | null | undefined) ?? null,
    address: (row.address as string | null | undefined) ?? null,
    city: (row.city as string | null | undefined) ?? null,
    type: (row.type as SupplierType | undefined) ?? "publisher",
    status: (row.status as SupplierStatus | undefined) ?? "active",
    isBranchAccount: true,
  });

  const fetchSuppliers = useCallback(async () => {
    setIsLoading(true);
    try {
      if (isAdminCatalog && adminViewMode === "canonical") {
        const data = await apiRequest("/suppliers");
        setSuppliers(Array.isArray(data) ? data : []);
      } else if (activeBranchId) {
        const data = await apiRequest(supplierAccountsUrl(activeBranchId));
        setSuppliers((Array.isArray(data) ? data : []).map(mapBranchAccountRow));
      } else {
        setSuppliers([]);
      }
    } catch (error) {
      const message = error instanceof ApiError ? error.message : t("suppliers.notFound");
      notify.rawError(message);
      setSuppliers([]);
    } finally {
      setIsLoading(false);
    }
  }, [activeBranchId, adminViewMode, isAdminCatalog, notify, t]);

  useEffect(() => {
    fetchSuppliers();
  }, [fetchSuppliers]);

  const openCreate = () => {
    if (isAdminCatalog && adminViewMode === "branch" && !activeBranchId) {
      notify.error("toast.supplierSaveError");
      return;
    }
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
      if (isAdminCatalog && adminViewMode === "canonical") {
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
      } else if (activeBranchId) {
        const payload = {
          branch_id: activeBranchId,
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
      const message = error instanceof ApiError ? error.message : t("toast.supplierSaveError");
      notify.rawError(message);
    } finally {
      setIsSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!pendingDelete) return;
    if (usingBranchAccounts) {
      await handleToggleStatus(pendingDelete);
      setPendingDelete(null);
      return;
    }
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
      if (isAdminCatalog && adminViewMode === "canonical") {
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
      const message = error instanceof ApiError ? error.message : t("toast.supplierSaveError");
      notify.rawError(message);
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

      {isAdminCatalog && (
        <Card className="relative z-20 overflow-visible rounded-2xl border border-white/70 bg-white/70 shadow-sm backdrop-blur-xl">
          <CardContent className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0 flex-1 space-y-3">
              <div className="inline-flex rounded-2xl bg-ink/[0.04] p-1">
                <button
                  type="button"
                  onClick={() => setAdminViewMode("canonical")}
                  className={cn(
                    "flex items-center gap-1.5 rounded-xl px-4 py-2 text-[11px] font-black transition-all",
                    adminViewMode === "canonical"
                      ? "bg-white text-primary shadow-sm"
                      : "text-ink/45 hover:text-ink/70"
                  )}
                >
                  <Users className="h-3.5 w-3.5" />
                  {t("suppliers.tabCanonical")}
                </button>
                <button
                  type="button"
                  onClick={() => setAdminViewMode("branch")}
                  className={cn(
                    "flex items-center gap-1.5 rounded-xl px-4 py-2 text-[11px] font-black transition-all",
                    adminViewMode === "branch"
                      ? "bg-white text-primary shadow-sm"
                      : "text-ink/45 hover:text-ink/70"
                  )}
                >
                  <Building2 className="h-3.5 w-3.5" />
                  {t("suppliers.tabBranchAccounts")}
                </button>
              </div>
              <p className="max-w-xl text-[11px] font-bold leading-5 text-ink/40">
                {adminViewMode === "branch" ? t("suppliers.branchHint") : t("suppliers.canonicalHint")}
              </p>
            </div>
            {adminViewMode === "branch" && (
              <div className="w-full shrink-0 sm:w-64">
                <p className="mb-1.5 text-[9px] font-black uppercase tracking-widest text-ink/30">
                  {t("suppliers.selectBranch")}
                </p>
                <FilterSelect
                  value={adminBranchId != null ? String(adminBranchId) : ""}
                  onChange={(value) => setAdminBranchId(value ? Number(value) : null)}
                  options={branches.map((b) => ({
                    value: String(b.id),
                    label: b.city ? `${b.name} · ${b.city}` : b.name,
                  }))}
                  className="w-full"
                />
              </div>
            )}
          </CardContent>
        </Card>
      )}

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
                      {isAdminCatalog && adminViewMode === "canonical" && (
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
                    <div className="flex flex-wrap items-center gap-1.5">
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
                      {supplier.isBranchAccount && (
                        <Badge variant="outline" className="text-[9px] border-primary/20 bg-primary/5 text-primary">
                          {t("suppliers.branchAccountBadge")}
                        </Badge>
                      )}
                    </div>
                    <Link
                      href={
                        usingBranchAccounts
                          ? `/dashboard/consignment?supplier_account_id=${supplier.id}`
                          : `/dashboard/consignment?supplier_id=${supplier.id}`
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
          className="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6"
          onClick={(e) => e.target === e.currentTarget && !isSaving && setShowForm(false)}
        >
          <div className="absolute inset-0 bg-ink/45 backdrop-blur-[6px]" />
          <div className="relative w-full max-w-lg overflow-hidden rounded-3xl border border-white/70 bg-white/95 font-ibm-plex-arabic shadow-[0_24px_80px_rgba(13,13,13,0.18)] backdrop-blur-2xl">
            <div className="pointer-events-none absolute -top-24 -end-16 h-56 w-56 rounded-full bg-primary/10 blur-3xl" />
            <form onSubmit={handleSubmit} className="relative">
              <div className="flex items-start justify-between gap-4 border-b border-ink/5 bg-parchment/30 px-5 py-4 sm:px-6">
                <div className="min-w-0 pt-0.5">
                  <h2 className="truncate text-[17px] font-bold font-ibm-plex-arabic tracking-tight text-ink">
                    {editingSupplier ? t("suppliers.form.editTitle") : t("suppliers.addModalTitle")}
                  </h2>
                  <p className="mt-1 text-[11px] font-medium font-ibm-plex-arabic leading-5 text-ink/40">
                    {t("suppliers.form.contactDesc")}
                  </p>
                </div>
                <button
                  type="button"
                  disabled={isSaving}
                  onClick={() => setShowForm(false)}
                  className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-ink/5 bg-white/80 text-ink/35 transition-all hover:border-primary/20 hover:bg-white hover:text-primary"
                  aria-label={t("common.close")}
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              <div className="max-h-[min(60vh,560px)] space-y-4 overflow-y-auto px-5 py-5 sm:px-6 scrollbar-hide">
                <div className="space-y-1.5">
                  <label className="text-[11px] font-bold font-ibm-plex-arabic text-ink/45">
                    {t("suppliers.form.name")}
                  </label>
                  <input
                    required
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder={t("suppliers.form.nameExample")}
                    className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-[12px] font-ibm-plex-arabic outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                  />
                </div>

                <div className="space-y-2">
                  <label className="text-[11px] font-bold font-ibm-plex-arabic text-ink/45">
                    {t("suppliers.form.type")}
                  </label>
                  <div className="grid grid-cols-3 gap-2">
                    {(["publisher", "company", "individual"] as SupplierType[]).map((type) => {
                      const selected = formData.type === type;
                      return (
                        <button
                          key={type}
                          type="button"
                          onClick={() => setFormData({ ...formData, type })}
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

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <label className="text-[11px] font-bold font-ibm-plex-arabic text-ink/45">
                      {t("suppliers.form.city")}
                    </label>
                    <input
                      value={formData.city}
                      onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                      placeholder={t("suppliers.form.cityExample")}
                      className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-[12px] font-ibm-plex-arabic outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <label className="text-[11px] font-bold font-ibm-plex-arabic text-ink/45">
                      {t("common.phone")}
                    </label>
                    <input
                      value={formData.phone}
                      onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                      className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-left text-[12px] font-ibm-plex-arabic tabular-nums outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <label className="text-[11px] font-bold font-ibm-plex-arabic text-ink/45">
                    {t("common.email")}
                  </label>
                  <input
                    type="email"
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    className="h-11 w-full rounded-xl border border-ink/8 bg-parchment/25 px-3 text-[12px] font-ibm-plex-arabic outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                  />
                </div>

                <div className="space-y-1.5">
                  <label className="text-[11px] font-bold font-ibm-plex-arabic text-ink/45">
                    {t("common.address")}
                  </label>
                  <textarea
                    rows={2}
                    value={formData.address}
                    onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                    className="w-full resize-none rounded-xl border border-ink/8 bg-parchment/25 px-3 py-2.5 text-[12px] font-ibm-plex-arabic outline-none transition-all focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10"
                  />
                </div>
              </div>

              <div className="flex gap-2 border-t border-ink/5 bg-parchment/20 px-5 py-4 sm:px-6">
                <Button
                  type="button"
                  variant="ghost"
                  className="h-11 flex-1 rounded-xl text-[12px] font-bold font-ibm-plex-arabic"
                  disabled={isSaving}
                  onClick={() => setShowForm(false)}
                >
                  {t("common.cancel")}
                </Button>
                <Button
                  type="submit"
                  isLoading={isSaving}
                  className="h-11 flex-[1.4] rounded-xl text-[12px] font-bold font-ibm-plex-arabic shadow-lg shadow-primary/15"
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
