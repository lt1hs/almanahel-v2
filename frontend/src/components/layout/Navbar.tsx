"use client";

import React, { useState, useRef, useEffect, useCallback, useMemo } from "react";
import { useRouter, usePathname } from "@/i18n/routing";
import {
    Bell, User, Search, Globe, ChevronDown, CheckCircle2, AlertTriangle, Info,
    LogOut, CreditCard, RefreshCw, Store, Settings, X, Languages, Trash2,
    LayoutDashboard, Library, Wallet, BarChart3, Truck, Warehouse, Users,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useTranslation } from "@/hooks/useTranslation";
import { useLocaleSwitch } from "@/hooks/useLocaleSwitch";
import { Badge } from "@/components/ui/Badge";
import { AnimatePresence, motion } from "framer-motion";
import { cn } from "@/lib/utils";
import { apiRequest } from "@/lib/api";
import { useNotificationInbox, useInvalidateNotifications } from "@/hooks/useNotificationInbox";

interface InboxNotification {
    id: number;
    title: string;
    description: string;
    unread: boolean;
    tone: "warning" | "info" | "success";
    icon: React.ElementType;
    color: string;
    bg: string;
    href?: string;
}

function inboxHref(type: string): string {
    if (type.startsWith("transfer")) return "/dashboard/distribution";
    if (type === "low_stock") return "/dashboard/inventory";
    if (type === "check_due") return "/dashboard/checks";
    if (type === "credit_due") return "/dashboard/sales";
    return "/dashboard/notifications";
}

function inboxStyle(type: string): { tone: InboxNotification["tone"]; color: string; bg: string; icon: React.ElementType } {
    if (type.startsWith("transfer") && type !== "transfer_received") return { tone: "warning", color: "text-sky-600", bg: "bg-sky-50", icon: Truck };
    if (type === "transfer_received") return { tone: "success", color: "text-emerald-600", bg: "bg-emerald-50", icon: Truck };
    if (type === "low_stock") return { tone: "warning", color: "text-amber-500", bg: "bg-amber-50", icon: AlertTriangle };
    if (type === "check_due") return { tone: "info", color: "text-sky-600", bg: "bg-sky-50", icon: CreditCard };
    if (type === "credit_due") return { tone: "info", color: "text-primary", bg: "bg-primary/5", icon: Info };
    return { tone: "info", color: "text-primary", bg: "bg-primary/5", icon: Bell };
}

interface QuickLink {
    titleKey: string;
    href: string;
    icon: React.ElementType;
    keywords: string[];
}

const QUICK_LINKS: QuickLink[] = [
    { titleKey: "nav.dashboard", href: "/dashboard", icon: LayoutDashboard, keywords: ["dashboard", "پیشخوان", "home"] },
    { titleKey: "nav.inventory", href: "/dashboard/inventory", icon: Library, keywords: ["inventory", "book", "کتاب", "موجودی", "barcode", "بارکد"] },
    { titleKey: "nav.warehouse", href: "/dashboard/warehouse", icon: Warehouse, keywords: ["warehouse", "انبار"] },
    { titleKey: "nav.distribution", href: "/dashboard/distribution", icon: Truck, keywords: ["distribution", "transfer", "توزیع", "انتقال"] },
    { titleKey: "nav.sales", href: "/dashboard/sales", icon: Wallet, keywords: ["sales", "invoice", "فروش", "صندوق", "فاکتور"] },
    { titleKey: "nav.finance", href: "/dashboard/finance", icon: BarChart3, keywords: ["finance", "profit", "مالی", "سود"] },
    { titleKey: "nav.suppliers", href: "/dashboard/suppliers", icon: Users, keywords: ["supplier", "تأمین", "تامین"] },
    { titleKey: "nav.checks", href: "/dashboard/checks", icon: CreditCard, keywords: ["check", "چک"] },
    { titleKey: "nav.admin", href: "/dashboard/admin", icon: Settings, keywords: ["admin", "settings", "تنظیمات", "مدیریت"] },
];

const PAGE_TITLE_KEYS: Record<string, string> = {
    "/dashboard": "nav.dashboard",
    "/dashboard/inventory": "nav.inventory",
    "/dashboard/warehouse": "nav.warehouse",
    "/dashboard/warehouse/log": "nav.warehouse",
    "/dashboard/distribution": "nav.distribution",
    "/dashboard/consignment": "nav.consignment",
    "/dashboard/gifts": "nav.gifts",
    "/dashboard/returns": "nav.returns",
    "/dashboard/suppliers": "nav.suppliers",
    "/dashboard/sales": "nav.sales",
    "/dashboard/checks": "nav.checks",
    "/dashboard/finance": "nav.finance",
    "/dashboard/finance/branch-profit": "nav.branchProfit",
    "/dashboard/expenses": "nav.expenses",
    "/dashboard/admin": "nav.admin",
    "/dashboard/admin/currency": "admin.currencySettings",
    "/dashboard/admin/categories": "admin.categorySettings",
    "/dashboard/admin/users": "admin.userManagement",
    "/dashboard/admin/activity": "nav.activityLog",
    "/dashboard/notifications": "common.notifications.title",
    "/dashboard/reports": "finance.title",
};

function resolvePageTitle(pathname: string): string | null {
    if (PAGE_TITLE_KEYS[pathname]) return PAGE_TITLE_KEYS[pathname];
    const match = Object.keys(PAGE_TITLE_KEYS)
        .filter((p) => p !== "/dashboard" && pathname.startsWith(p + "/"))
        .sort((a, b) => b.length - a.length)[0];
    return match ? PAGE_TITLE_KEYS[match] : PAGE_TITLE_KEYS["/dashboard"];
}

export function Navbar() {
    const { user, logout } = useAuth();
    const { t, language, currency, setCurrency, isArabic } = useTranslation();
    const { switchLocale } = useLocaleSwitch();
    const router = useRouter();
    const pathname = usePathname();

    const [search, setSearch] = useState("");
    const [showSearch, setShowSearch] = useState(false);
    const [showNotifs, setShowNotifs] = useState(false);
    const [showProfile, setShowProfile] = useState(false);

    const searchRef = useRef<HTMLDivElement>(null);
    const notifRef = useRef<HTMLDivElement>(null);
    const profileRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const pageTitleKey = resolvePageTitle(pathname);

    const { data: inbox, isFetching: notifLoading, refetch: fetchNotifications } = useNotificationInbox(!!user);
    const invalidateNotifications = useInvalidateNotifications();

    const notifications: InboxNotification[] = useMemo(() => {
        return (inbox?.rows ?? []).map((row) => {
            const style = inboxStyle(row.type);
            return {
                id: row.id,
                title: row.title,
                description: row.body || "",
                unread: !row.read_at,
                tone: style.tone,
                icon: style.icon,
                color: style.color,
                bg: style.bg,
                href: inboxHref(row.type),
            };
        });
    }, [inbox]);

    const unreadCount = inbox?.unread ?? 0;

    const markInboxRead = useCallback(async () => {
        await apiRequest("/notifications/read-all", { method: "POST" });
        invalidateNotifications();
    }, [invalidateNotifications]);

    const dismissOne = useCallback(async (id: number) => {
        await apiRequest(`/notifications/${id}/dismiss`, { method: "POST" });
        invalidateNotifications();
    }, [invalidateNotifications]);

    const dismissAll = useCallback(async () => {
        await apiRequest("/notifications/dismiss-all", { method: "POST" });
        invalidateNotifications();
    }, [invalidateNotifications]);

    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (searchRef.current && !searchRef.current.contains(event.target as Node)) {
                setShowSearch(false);
            }
            if (notifRef.current && !notifRef.current.contains(event.target as Node)) {
                setShowNotifs(false);
            }
            if (profileRef.current && !profileRef.current.contains(event.target as Node)) {
                setShowProfile(false);
            }
        }
        function handleEscape(event: KeyboardEvent) {
            if (event.key === "Escape") {
                setShowSearch(false);
                setShowNotifs(false);
                setShowProfile(false);
            }
        }
        document.addEventListener("mousedown", handleClickOutside);
        document.addEventListener("keydown", handleEscape);
        return () => {
            document.removeEventListener("mousedown", handleClickOutside);
            document.removeEventListener("keydown", handleEscape);
        };
    }, []);

    const roleLabels: Record<string, { labelKey: string; color: string }> = {
        super_admin:     { labelKey: "roles.super_admin",     color: "text-violet-600" },
        admin:           { labelKey: "roles.admin",           color: "text-primary" },
        branch_manager:  { labelKey: "roles.branch_manager",  color: "text-accent" },
        warehouse_staff: { labelKey: "roles.warehouse_staff", color: "text-sky-600" },
        accountant:      { labelKey: "roles.accountant",      color: "text-ink/60" },
    };

    const roleInfo = (user && roleLabels[user.role])
        ?? { labelKey: "roles.guest", color: "text-ink/40" };

    const searchResults = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return QUICK_LINKS.slice(0, 6);
        return QUICK_LINKS.filter((link) => {
            const title = t(link.titleKey).toLowerCase();
            return title.includes(q) || link.keywords.some((k) => k.toLowerCase().includes(q));
        }).slice(0, 8);
    }, [search, t]);

    const navigateTo = (href: string) => {
        router.push(href);
        setSearch("");
        setShowSearch(false);
    };

    const handleSearchSubmit = () => {
        const q = search.trim();
        if (!q) return;
        if (searchResults.length > 0) {
            navigateTo(searchResults[0].href);
            return;
        }
        navigateTo("/dashboard/inventory");
    };

    const closeAllMenus = () => {
        setShowSearch(false);
        setShowNotifs(false);
        setShowProfile(false);
    };

    return (
        <header className="h-14 bg-white/85 backdrop-blur-2xl border-b border-ink/[0.06] flex items-center justify-between gap-4 px-4 md:px-6 sticky top-0 z-40 shadow-sm shadow-ink/[0.03]">
            {/* Page context */}
            <div className="hidden lg:flex flex-col min-w-0 shrink-0 max-w-[220px]">
                <h2 className="text-[13px] font-black font-vazirmatn text-ink truncate leading-tight">
                    {pageTitleKey ? t(pageTitleKey) : t("nav.dashboard")}
                </h2>
                {user?.branch?.name && (
                    <div className="flex items-center gap-1 mt-0.5 text-[9px] font-bold text-ink/35 truncate">
                        <Store className="w-3 h-3 shrink-0 text-primary/50" />
                        <span className="truncate">{user.branch.name}</span>
                    </div>
                )}
            </div>

            {/* Global search */}
            <div ref={searchRef} className="flex-1 flex items-center max-w-xl mx-auto w-full relative">
                <div className="relative w-full group">
                    <Search className={cn(
                        "absolute inset-y-0 my-auto w-3.5 h-3.5 text-ink/25 group-focus-within:text-primary transition-colors pointer-events-none",
                        isArabic ? "right-3" : "left-3"
                    )} />
                    <input
                        ref={inputRef}
                        type="search"
                        value={search}
                        placeholder={t("navbar.searchPlaceholder")}
                        onChange={(e) => { setSearch(e.target.value); setShowSearch(true); }}
                        onFocus={() => setShowSearch(true)}
                        onKeyDown={(e) => {
                            if (e.key === "Enter") handleSearchSubmit();
                        }}
                        className={cn(
                            "w-full h-9 bg-parchment/50 border border-ink/[0.06] focus:border-primary/30 focus:bg-white rounded-xl text-[11px] font-vazirmatn transition-all outline-none text-ink placeholder:text-ink/25 shadow-sm focus:ring-2 focus:ring-primary/10",
                            isArabic ? "pr-9 pl-9" : "pl-9 pr-9"
                        )}
                    />
                    {search && (
                        <button
                            type="button"
                            aria-label={t("common.clear")}
                            onClick={() => { setSearch(""); inputRef.current?.focus(); }}
                            className={cn(
                                "absolute inset-y-0 my-auto w-5 h-5 flex items-center justify-center rounded-full bg-ink/8 hover:bg-ink/15 text-ink/40",
                                isArabic ? "left-3" : "right-3"
                            )}
                        >
                            <X className="w-3 h-3" />
                        </button>
                    )}
                </div>

                <AnimatePresence>
                    {showSearch && (
                        <motion.div
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: 6 }}
                            transition={{ duration: 0.15 }}
                            className="absolute top-[calc(100%+6px)] inset-x-0 bg-white/95 backdrop-blur-xl rounded-xl border border-ink/[0.06] shadow-xl shadow-ink/10 z-50 overflow-hidden"
                        >
                            <div className="px-3 py-2 border-b border-ink/[0.05] text-[9px] font-black text-ink/30 uppercase tracking-widest">
                                {t("navbar.quickNav")}
                            </div>
                            <div className="py-1 max-h-64 overflow-y-auto">
                                {searchResults.map((link) => (
                                    <button
                                        key={link.href}
                                        type="button"
                                        onClick={() => navigateTo(link.href)}
                                        className="w-full flex items-center gap-3 px-3 py-2.5 hover:bg-primary/[0.04] transition-colors text-start"
                                    >
                                        <div className="w-8 h-8 rounded-lg bg-parchment/80 border border-ink/[0.05] flex items-center justify-center shrink-0">
                                            <link.icon className="w-4 h-4 text-primary/70" />
                                        </div>
                                        <span className="text-[11px] font-bold font-vazirmatn text-ink">{t(link.titleKey)}</span>
                                    </button>
                                ))}
                                {searchResults.length === 0 && (
                                    <p className="px-3 py-4 text-[10px] text-ink/30 font-vazirmatn text-center">{t("common.noResults")}</p>
                                )}
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>
            </div>

            {/* Actions */}
            <div className="flex items-center gap-1.5 shrink-0">
                {/* Currency */}
                <div className="hidden sm:flex items-center p-0.5 bg-parchment/60 rounded-lg border border-ink/[0.06]">
                    {(["TOMAN", "IQD"] as const).map((c) => (
                        <button
                            key={c}
                            type="button"
                            onClick={() => setCurrency(c)}
                            className={cn(
                                "px-2.5 py-1 rounded-md text-[9px] font-black font-vazirmatn transition-all",
                                currency === c
                                    ? "bg-white text-primary shadow-sm"
                                    : "text-ink/40 hover:text-ink/60"
                            )}
                        >
                            {c === "IQD" ? t("common.dinar") : t("common.toman")}
                        </button>
                    ))}
                </div>

                {/* Language */}
                <button
                    type="button"
                    onClick={() => switchLocale()}
                    title={t("navbar.switchLanguage")}
                    className="hidden md:flex items-center gap-1.5 h-9 px-2.5 rounded-lg border border-ink/[0.06] bg-white/60 hover:bg-white hover:border-primary/20 transition-all"
                >
                    <Languages className="w-3.5 h-3.5 text-primary/60" />
                    <span className="text-[9px] font-black font-vazirmatn text-ink/50">
                        {language === "ar" ? "العربية" : "فارسی"}
                    </span>
                </button>

                {/* Notifications */}
                <div className="relative" ref={notifRef}>
                    <button
                        type="button"
                        aria-label={t("common.notifications.title")}
                        onClick={async () => {
                            const opening = !showNotifs;
                            setShowNotifs(opening);
                            setShowProfile(false);
                            if (!opening) return;
                            await fetchNotifications();
                            try {
                                await markInboxRead();
                            } catch {
                                /* keep unread if the API is unreachable */
                            }
                        }}
                        className={cn(
                            "relative h-9 w-9 flex items-center justify-center text-ink/30 hover:text-primary transition-all rounded-lg hover:bg-white border border-transparent hover:border-ink/[0.06]",
                            showNotifs && "text-primary bg-white border-ink/[0.08] shadow-sm"
                        )}
                    >
                        <Bell className="w-4 h-4" />
                        {unreadCount > 0 && (
                            <span className="absolute top-1.5 end-1.5 min-w-[15px] h-[15px] px-0.5 bg-rose-500 rounded-full text-[7px] font-black text-white flex items-center justify-center ring-2 ring-white">
                                {unreadCount > 9 ? "9+" : unreadCount}
                            </span>
                        )}
                    </button>

                    <AnimatePresence>
                        {showNotifs && (
                            <motion.div
                                initial={{ opacity: 0, y: 8 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: 8 }}
                                transition={{ duration: 0.15 }}
                                className="absolute top-[calc(100%+8px)] end-0 w-80 bg-white/95 backdrop-blur-xl rounded-xl border border-ink/[0.06] shadow-2xl shadow-ink/10 z-50 overflow-hidden"
                            >
                                <div className="p-3 border-b border-ink/[0.05] flex items-center justify-between bg-parchment/30">
                                    <h3 className="text-[11px] font-black text-ink font-vazirmatn">{t("common.notifications.title")}</h3>
                                    <div className="flex items-center gap-2">
                                        {unreadCount > 0 && <Badge className="text-[8px]">{unreadCount}</Badge>}
                                        <button
                                            type="button"
                                            onClick={() => markInboxRead().catch(() => undefined)}
                                            className="text-[8px] font-black text-ink/35 hover:text-primary font-vazirmatn"
                                        >
                                            {t("common.notifications.markAllRead")}
                                        </button>
                                        {notifications.length > 0 && (
                                            <button
                                                type="button"
                                                onClick={() => dismissAll().catch(() => undefined)}
                                                className="text-[8px] font-black text-rose-400 hover:text-rose-600 font-vazirmatn"
                                            >
                                                {t("common.notifications.dismissAll")}
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            onClick={() => void fetchNotifications()}
                                            disabled={notifLoading}
                                            className="p-1 rounded-md hover:bg-white text-ink/30 hover:text-primary transition-colors disabled:opacity-40"
                                            aria-label={t("common.refresh")}
                                        >
                                            <RefreshCw className={cn("w-3.5 h-3.5", notifLoading && "animate-spin")} />
                                        </button>
                                    </div>
                                </div>

                                <div className="max-h-[320px] overflow-y-auto py-1">
                                    {notifications.length > 0 ? notifications.map((n) => (
                                        <div
                                            key={n.id}
                                            className={cn(
                                                "w-full px-3 py-2.5 hover:bg-primary/[0.03] transition-colors flex gap-2 border-b border-ink/[0.03] last:border-0",
                                                n.unread && "bg-primary/[0.03]"
                                            )}
                                        >
                                            <button
                                                type="button"
                                                onClick={async () => {
                                                    if (n.unread) {
                                                        try {
                                                            await apiRequest(`/notifications/${n.id}/read`, { method: "POST" });
                                                            invalidateNotifications();
                                                        } catch {
                                                            /* navigation still proceeds */
                                                        }
                                                    }
                                                    if (n.href) router.push(n.href);
                                                    setShowNotifs(false);
                                                }}
                                                className="flex-1 min-w-0 text-start flex gap-3"
                                            >
                                                <div className={cn("w-8 h-8 rounded-lg flex items-center justify-center shrink-0", n.bg)}>
                                                    <n.icon className={cn("w-4 h-4", n.color)} />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <span className={cn("text-[10px] block font-vazirmatn", n.unread ? "font-black text-ink" : "font-bold text-ink/55")}>{n.title}</span>
                                                    <p className="text-[9px] text-ink/45 leading-relaxed mt-0.5 line-clamp-2 font-vazirmatn">{n.description}</p>
                                                </div>
                                                {n.unread && <span className="mt-1.5 w-1.5 h-1.5 rounded-full bg-rose-500 shrink-0" />}
                                            </button>
                                            <button
                                                type="button"
                                                aria-label={t("common.notifications.dismiss")}
                                                title={t("common.notifications.dismiss")}
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    dismissOne(n.id).catch(() => undefined);
                                                }}
                                                className="shrink-0 h-7 w-7 mt-0.5 rounded-lg text-ink/25 hover:text-rose-600 hover:bg-rose-50 flex items-center justify-center"
                                            >
                                                <Trash2 className="w-3.5 h-3.5" />
                                            </button>
                                        </div>
                                    )) : (
                                        <div className="py-10 text-center text-[10px] text-ink/30 font-vazirmatn">
                                            <CheckCircle2 className="w-7 h-7 mx-auto mb-2 text-emerald-400/70" />
                                            {t("navbar.notif.noNew")}
                                        </div>
                                    )}
                                </div>

                                <div className="p-2 border-t border-ink/[0.05] bg-parchment/20 text-center">
                                    <button
                                        type="button"
                                        onClick={() => { router.push("/dashboard/notifications"); setShowNotifs(false); }}
                                        className="text-[9px] font-black text-ink/40 hover:text-primary transition-colors font-vazirmatn"
                                    >
                                        {t("common.notifications.viewAll")}
                                    </button>
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>

                <div className="hidden sm:block h-6 w-px bg-ink/[0.08]" />

                {/* Profile */}
                <div className="relative" ref={profileRef}>
                    <button
                        type="button"
                        onClick={() => { setShowProfile((v) => !v); setShowNotifs(false); }}
                        className={cn(
                            "flex items-center gap-2 ps-1 pe-2 h-9 rounded-xl transition-all border border-transparent hover:border-ink/[0.06] hover:bg-white/80",
                            showProfile && "bg-white border-ink/[0.08] shadow-sm"
                        )}
                    >
                        <div className="w-8 h-8 rounded-lg bg-gradient-to-br from-primary/15 to-primary/5 border border-primary/15 flex items-center justify-center shrink-0">
                            <User className="w-4 h-4 text-primary/70" />
                        </div>
                        <div className="hidden md:flex flex-col items-end min-w-0 max-w-[120px]">
                            <span className="text-[10px] font-black text-ink leading-none truncate w-full text-end font-vazirmatn">
                                {user?.name}
                            </span>
                            <span className={cn("text-[8px] font-bold mt-0.5 truncate w-full text-end font-vazirmatn", roleInfo.color)}>
                                {t(roleInfo.labelKey)}
                            </span>
                        </div>
                        <ChevronDown className={cn("w-3 h-3 text-ink/25 transition-transform hidden md:block", showProfile && "rotate-180")} />
                    </button>

                    <AnimatePresence>
                        {showProfile && (
                            <motion.div
                                initial={{ opacity: 0, y: 8 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: 8 }}
                                transition={{ duration: 0.15 }}
                                className="absolute top-[calc(100%+8px)] end-0 w-60 bg-white/95 backdrop-blur-xl rounded-xl border border-ink/[0.06] shadow-2xl shadow-ink/10 z-50 overflow-hidden"
                            >
                                <div className="px-4 py-3 border-b border-ink/[0.05] bg-gradient-to-l from-primary/[0.04] to-transparent">
                                    <p className="text-[11px] font-black text-ink font-vazirmatn truncate">{user?.name}</p>
                                    <p className={cn("text-[9px] font-bold mt-0.5 font-vazirmatn", roleInfo.color)}>{t(roleInfo.labelKey)}</p>
                                    {user?.branch?.name && (
                                        <p className="text-[9px] text-ink/35 mt-1 flex items-center gap-1 font-vazirmatn">
                                            <Store className="w-3 h-3 shrink-0" />
                                            <span className="truncate">{user.branch.name}</span>
                                        </p>
                                    )}
                                </div>

                                <div className="p-1.5">
                                    {(user?.role === "super_admin" || user?.role === "admin") && (
                                        <button
                                            type="button"
                                            onClick={() => { navigateTo("/dashboard/admin"); setShowProfile(false); }}
                                            className="w-full flex items-center gap-2.5 px-3 py-2 text-[10px] font-bold text-ink/70 hover:bg-parchment/60 hover:text-ink transition-all rounded-lg font-vazirmatn"
                                        >
                                            <Settings className="w-3.5 h-3.5 text-ink/40" />
                                            {t("common.userMenu.settings")}
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => { switchLocale(); closeAllMenus(); }}
                                        className="w-full flex items-center gap-2.5 px-3 py-2 text-[10px] font-bold text-ink/70 hover:bg-parchment/60 hover:text-ink transition-all rounded-lg font-vazirmatn md:hidden"
                                    >
                                        <Globe className="w-3.5 h-3.5 text-ink/40" />
                                        {t("navbar.switchLanguage")}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => { logout(); closeAllMenus(); }}
                                        className="w-full flex items-center gap-2.5 px-3 py-2 text-[10px] font-bold text-rose-600 hover:bg-rose-50 transition-all rounded-lg font-vazirmatn"
                                    >
                                        <LogOut className="w-3.5 h-3.5" />
                                        {t("common.userMenu.logout")}
                                    </button>
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>
            </div>
        </header>
    );
}
