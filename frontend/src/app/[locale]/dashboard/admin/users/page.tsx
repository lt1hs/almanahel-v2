"use client";

import { usePageReady } from "@/components/NavigationProgress";

import React, { useState, useEffect, useCallback, useMemo } from "react";
import { motion, Variants } from "framer-motion";
import {
    UserCog, ArrowRight, ArrowLeft, Plus, RefreshCw, Search,
    Mail, Building2, Shield, Pencil, UserX, X,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { FilterSelect } from "@/components/ui/FilterSelect";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { apiRequest } from "@/lib/api";
import { cn } from "@/lib/utils";

const fadeUp: Variants = {
    hidden: { opacity: 0, y: 10 },
    show: { opacity: 1, y: 0, transition: { type: "spring", stiffness: 400, damping: 30 } },
};

const ROLES = ["super_admin", "admin", "branch_manager", "warehouse_staff", "accountant"] as const;
const STATUSES = ["active", "inactive", "suspended"] as const;

type Role = (typeof ROLES)[number];
type Status = (typeof STATUSES)[number];

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: Role;
    branch_id: number | null;
    branch?: { id: number; name: string; city?: string } | null;
    status: Status;
}

const EMPTY_FORM = {
    name: "",
    email: "",
    password: "",
    role: "branch_manager" as Role,
    branch_id: "",
    status: "active" as Status,
};

export default function UserManagementPage() {
    const { t, isArabic } = useTranslation();
    const notify = useNotify();
    const router = useRouter();
    const { user: currentUser } = useAuth();

    const [users, setUsers] = useState<UserRow[]>([]);
    const [branches, setBranches] = useState<any[]>([]);
    const [search, setSearch] = useState("");
    const [roleFilter, setRoleFilter] = useState("all");
    const [isLoading, setIsLoading] = useState(true);
    usePageReady(!isLoading);
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState(EMPTY_FORM);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const isAdmin = currentUser?.role === "super_admin" || currentUser?.role === "admin";
    const isSuperAdmin = currentUser?.role === "super_admin";

    const roleOptions = useMemo(() => {
        const list = isSuperAdmin ? ROLES : ROLES.filter((r) => r !== "super_admin");
        return [
            { value: "all", label: t("userManagement.allRoles") },
            ...list.map((r) => ({ value: r, label: t(`roles.${r}`) })),
        ];
    }, [isSuperAdmin, t]);

    const formRoleOptions = useMemo(() => {
        const list = isSuperAdmin ? ROLES : ROLES.filter((r) => r !== "super_admin");
        return list.map((r) => ({ value: r, label: t(`roles.${r}`) }));
    }, [isSuperAdmin, t]);

    const fetchData = useCallback(async () => {
        setIsLoading(true);
        try {
            const [uData, bData] = await Promise.all([
                apiRequest("/users"),
                apiRequest("/branches"),
            ]);
            setUsers(Array.isArray(uData) ? uData : []);
            setBranches(Array.isArray(bData) ? bData : []);
        } catch (error) {
            console.error("Users fetch failed:", error);
        } finally {
            setIsLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return users.filter((u) => {
            if (roleFilter !== "all" && u.role !== roleFilter) return false;
            if (!q) return true;
            return u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q);
        });
    }, [users, search, roleFilter]);

    const openCreate = () => {
        setEditingId(null);
        setForm(EMPTY_FORM);
        setShowForm(true);
    };

    const openEdit = (u: UserRow) => {
        setEditingId(u.id);
        setForm({
            name: u.name,
            email: u.email,
            password: "",
            role: u.role,
            branch_id: u.branch_id ? String(u.branch_id) : "",
            status: u.status,
        });
        setShowForm(true);
    };

    const closeForm = () => {
        setShowForm(false);
        setEditingId(null);
        setForm(EMPTY_FORM);
    };

    const needsBranch = ["branch_manager", "warehouse_staff", "accountant"].includes(form.role);

    const handleSubmit = async () => {
        if (!form.name.trim() || !form.email.trim()) {
            notify.error("userManagement.nameEmailRequired");
            return;
        }
        if (!editingId && !form.password) {
            notify.error("userManagement.passwordRequired");
            return;
        }
        if (needsBranch && !form.branch_id) {
            notify.error("userManagement.branchRequired");
            return;
        }

        setIsSubmitting(true);
        try {
            const payload: Record<string, unknown> = {
                name: form.name.trim(),
                email: form.email.trim(),
                role: form.role,
                status: form.status,
                branch_id: form.branch_id ? parseInt(form.branch_id, 10) : null,
            };
            if (form.password) payload.password = form.password;

            if (editingId) {
                await apiRequest(`/users/${editingId}`, {
                    method: "PUT",
                    body: JSON.stringify(payload),
                });
                notify.success("userManagement.updated");
            } else {
                await apiRequest("/users", {
                    method: "POST",
                    body: JSON.stringify({ ...payload, password: form.password }),
                });
                notify.success("userManagement.created");
            }
            closeForm();
            fetchData();
        } catch (error) {
            const msg = error instanceof Error ? error.message : "";
            if (msg) notify.rawError(msg);
            else notify.error("userManagement.saveError");
        } finally {
            setIsSubmitting(false);
        }
    };

    const deactivateUser = async (u: UserRow) => {
        if (u.id === Number(currentUser?.id)) {
            notify.error("userManagement.cannotDeactivateSelf");
            return;
        }
        if (!confirm(t("userManagement.confirmDeactivate", { name: u.name }))) return;
        try {
            await apiRequest(`/users/${u.id}`, { method: "DELETE" });
            notify.success("userManagement.deactivated");
            fetchData();
        } catch (error) {
            notify.error("userManagement.saveError");
        }
    };

    const statusBadge = (status: Status) => {
        const map: Record<Status, string> = {
            active: "bg-emerald-50 text-emerald-600 border-emerald-100",
            inactive: "bg-parchment text-ink/40 border-ink/5",
            suspended: "bg-rose-50 text-rose-600 border-rose-100",
        };
        return map[status] ?? map.inactive;
    };

    if (!isAdmin) {
        return (
            <div className="p-8 text-center">
                <p className="text-sm font-bold text-ink/40">{t("userManagement.accessDenied")}</p>
                <Button variant="ghost" className="mt-4" onClick={() => router.push("/dashboard")}>
                    {t("common.back")}
                </Button>
            </div>
        );
    }

    return (
        <motion.div
            initial="hidden"
            animate="show"
            variants={{ show: { transition: { staggerChildren: 0.05 } } }}
            className="space-y-6 pb-12"
        >
            <motion.div variants={fadeUp} className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-9 w-9 p-0 rounded-[7px]"
                        onClick={() => router.push("/dashboard/admin")}
                    >
                        {isArabic ? (
                            <ArrowRight className="w-4 h-4 text-ink/40" />
                        ) : (
                            <ArrowLeft className="w-4 h-4 text-ink/40" />
                        )}
                    </Button>
                    <div>
                        <h1 className="text-2xl font-black font-vazirmatn text-ink flex items-center gap-2">
                            <UserCog className="w-6 h-6 text-primary" />
                            {t("userManagement.title")}
                        </h1>
                        <p className="text-[11px] text-ink/40 font-rubik mt-0.5 uppercase tracking-wider">
                            {t("userManagement.subtitle")}
                        </p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" className="h-9 rounded-[7px] text-[11px]" onClick={fetchData} disabled={isLoading}>
                        <RefreshCw className={cn("w-3.5 h-3.5", isLoading && "animate-spin")} />
                        {t("common.refresh")}
                    </Button>
                    <Button variant="primary" size="sm" className="h-9 px-4 rounded-[7px] text-[11px] font-black" onClick={openCreate}>
                        <Plus className="w-3.5 h-3.5 ml-1" />
                        {t("userManagement.addUser")}
                    </Button>
                </div>
            </motion.div>

            <motion.div variants={fadeUp}>
                <Card className="border border-ink/5 bg-white shadow-sm rounded-[7px] overflow-hidden">
                    <CardHeader className="p-4 border-b border-ink/5 flex flex-col sm:flex-row gap-3 sm:items-center sm:justify-between">
                        <CardTitle className="text-xs font-black uppercase tracking-widest text-ink/80">
                            {t("userManagement.userList")} ({filtered.length})
                        </CardTitle>
                        <div className="flex flex-wrap gap-2">
                            <div className="relative">
                                <Search className="absolute inset-y-0 start-3 my-auto w-3.5 h-3.5 text-ink/25 pointer-events-none" />
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder={t("userManagement.searchPlaceholder")}
                                    className="h-9 w-48 ps-9 pe-3 rounded-[7px] border border-ink/10 text-[11px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/20"
                                />
                            </div>
                            <FilterSelect
                                value={roleFilter}
                                onChange={setRoleFilter}
                                options={roleOptions}
                                icon={<Shield className="w-3.5 h-3.5" />}
                            />
                        </div>
                    </CardHeader>
                    <CardContent className="p-2">
                        {isLoading ? (
                            <div className="p-8 text-center text-[11px] text-ink/30 animate-pulse">{t("common.loading")}</div>
                        ) : filtered.length === 0 ? (
                            <div className="p-8 text-center text-[11px] text-ink/30">{t("common.noResults")}</div>
                        ) : (
                            <div className="space-y-0.5">
                                {filtered.map((u) => (
                                    <div
                                        key={u.id}
                                        className="flex items-center justify-between p-3.5 rounded-[7px] hover:bg-parchment/30 transition-all group gap-3"
                                    >
                                        <div className="flex items-center gap-4 min-w-0">
                                            <div className="w-9 h-9 bg-parchment border border-ink/5 rounded-[7px] flex items-center justify-center shrink-0">
                                                <UserCog className="w-4 h-4 text-ink/20 group-hover:text-primary/50" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="font-vazirmatn font-black text-[13px] text-ink truncate">{u.name}</p>
                                                <p className="text-[9px] text-ink/30 font-rubik mt-0.5 flex items-center gap-1 truncate">
                                                    <Mail className="w-3 h-3 shrink-0" />
                                                    {u.email}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 shrink-0">
                                            <div className="text-end hidden sm:block">
                                                <p className="text-[8px] text-ink/20 font-black uppercase tracking-widest">{t("userManagement.branch")}</p>
                                                <p className="text-[10px] font-bold text-ink/60 flex items-center gap-1 justify-end">
                                                    <Building2 className="w-3 h-3" />
                                                    {u.branch?.name ?? "—"}
                                                </p>
                                            </div>
                                            <Badge className="text-[8px] font-black h-5 px-2 rounded-[4px] bg-primary/5 text-primary border-primary/10">
                                                {t(`roles.${u.role}`)}
                                            </Badge>
                                            <Badge className={cn("text-[8px] font-black h-5 px-2 rounded-[4px]", statusBadge(u.status))}>
                                                {t(`userManagement.status.${u.status}`)}
                                            </Badge>
                                            <button
                                                type="button"
                                                onClick={() => openEdit(u)}
                                                className="p-2 hover:bg-parchment rounded-[5px] text-ink/20 hover:text-primary transition-colors"
                                                title={t("common.edit")}
                                            >
                                                <Pencil className="w-3.5 h-3.5" />
                                            </button>
                                            {u.status === "active" && u.id !== Number(currentUser?.id) && (
                                                <button
                                                    type="button"
                                                    onClick={() => deactivateUser(u)}
                                                    className="p-2 hover:bg-rose-50 rounded-[5px] text-ink/20 hover:text-rose-500 transition-colors"
                                                    title={t("userManagement.deactivate")}
                                                >
                                                    <UserX className="w-3.5 h-3.5" />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </motion.div>

            {showForm && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-ink/30 backdrop-blur-sm"
                    onClick={(e) => e.target === e.currentTarget && closeForm()}
                >
                    <div className="w-full max-w-md bg-white rounded-2xl shadow-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between">
                            <h3 className="text-[15px] font-black font-vazirmatn">
                                {editingId ? t("userManagement.editUser") : t("userManagement.addUser")}
                            </h3>
                            <button type="button" onClick={closeForm} className="p-2 rounded-lg hover:bg-parchment text-ink/30">
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <input
                            placeholder={t("userManagement.fullName")}
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none focus:ring-2 focus:ring-primary/20"
                        />
                        <input
                            type="email"
                            placeholder={t("auth.email")}
                            value={form.email}
                            onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] outline-none focus:ring-2 focus:ring-primary/20"
                        />
                        <input
                            type="password"
                            placeholder={editingId ? t("userManagement.passwordOptional") : t("auth.password")}
                            value={form.password}
                            onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] outline-none focus:ring-2 focus:ring-primary/20"
                        />

                        <select
                            value={form.role}
                            onChange={(e) => setForm((f) => ({ ...f, role: e.target.value as Role }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none"
                        >
                            {formRoleOptions.map((o) => (
                                <option key={o.value} value={o.value}>{o.label}</option>
                            ))}
                        </select>

                        <select
                            value={form.branch_id}
                            onChange={(e) => setForm((f) => ({ ...f, branch_id: e.target.value }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none"
                        >
                            <option value="">{needsBranch ? t("userManagement.selectBranch") : t("userManagement.noBranch")}</option>
                            {branches.map((b) => (
                                <option key={b.id} value={b.id}>{b.name} — {b.city}</option>
                            ))}
                        </select>

                        <select
                            value={form.status}
                            onChange={(e) => setForm((f) => ({ ...f, status: e.target.value as Status }))}
                            className="w-full h-10 rounded-xl border border-ink/10 px-3 text-[12px] font-vazirmatn outline-none"
                        >
                            {STATUSES.map((s) => (
                                <option key={s} value={s}>{t(`userManagement.status.${s}`)}</option>
                            ))}
                        </select>

                        <div className="flex gap-2 pt-2">
                            <Button variant="ghost" className="flex-1" onClick={closeForm}>{t("common.cancel")}</Button>
                            <Button variant="primary" className="flex-1" onClick={handleSubmit} disabled={isSubmitting}>
                                {isSubmitting ? t("common.saving") : t("common.save")}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </motion.div>
    );
}
