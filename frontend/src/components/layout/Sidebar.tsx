"use client";

import React, { useEffect } from "react";
import Image from "next/image";
import { Link, usePathname } from "@/i18n/routing";
import { motion, AnimatePresence, useReducedMotion } from "framer-motion";
import {
    LayoutDashboard,
    Library,
    Truck,
    Wallet,
    BarChart3,
    Settings,
    ChevronRight,
    LogOut,
    ShieldCheck,
    Globe,
    PackageCheck,
    Warehouse,
    Gift,
    RotateCcw,
    CreditCard,
    HandCoins,
    Users,
    ContactRound,
    Activity,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useAuth } from "@/contexts/AuthContext";
import { useTranslation } from "@/hooks/useTranslation";
import { useLocaleSwitch } from "@/hooks/useLocaleSwitch";
import { canAccessRoute } from "@/lib/routeAccess";

interface NavItem {
    titleKey: string;
    href: string;
    icon: React.ElementType;
    color: string;
}

interface NavGroup {
    titleKey: string;
    items: NavItem[];
}

const NAV_GROUPS: NavGroup[] = [
    {
        titleKey: "nav.groups.main",
        items: [
            { titleKey: "nav.dashboard", href: "/dashboard", icon: LayoutDashboard, color: "text-primary" },
        ],
    },
    {
        titleKey: "nav.groups.stock",
        items: [
            { titleKey: "nav.inventory", href: "/dashboard/inventory", icon: Library, color: "text-amber-500" },
            { titleKey: "nav.warehouse", href: "/dashboard/warehouse", icon: Warehouse, color: "text-sky-500" },
            { titleKey: "nav.distribution", href: "/dashboard/distribution", icon: Truck, color: "text-emerald-500" },
        ],
    },
    {
        titleKey: "nav.groups.consignment",
        items: [
            { titleKey: "nav.consignment", href: "/dashboard/consignment", icon: PackageCheck, color: "text-violet-500" },
            { titleKey: "nav.gifts", href: "/dashboard/gifts", icon: Gift, color: "text-rose-400" },
            { titleKey: "nav.returns", href: "/dashboard/returns", icon: RotateCcw, color: "text-indigo-500" },
        ],
    },
    {
        titleKey: "nav.groups.financial",
        items: [
            { titleKey: "nav.sales", href: "/dashboard/sales", icon: Wallet, color: "text-rose-500" },
            { titleKey: "nav.checks", href: "/dashboard/checks", icon: CreditCard, color: "text-teal-500" },
            { titleKey: "nav.credits", href: "/dashboard/credits", icon: HandCoins, color: "text-violet-500" },
            { titleKey: "nav.finance", href: "/dashboard/finance", icon: BarChart3, color: "text-indigo-500" },
            { titleKey: "nav.expenses", href: "/dashboard/expenses", icon: Wallet, color: "text-orange-500" },
            { titleKey: "nav.branchProfit", href: "/dashboard/finance/branch-profit", icon: BarChart3, color: "text-cyan-500" },
        ],
    },
    {
        titleKey: "nav.groups.system",
        items: [
            { titleKey: "nav.customers", href: "/dashboard/customers", icon: ContactRound, color: "text-sky-600" },
            { titleKey: "nav.suppliers", href: "/dashboard/suppliers", icon: Users, color: "text-amber-600" },
            { titleKey: "nav.admin", href: "/dashboard/admin", icon: Settings, color: "text-slate-500" },
            { titleKey: "nav.activityLog", href: "/dashboard/admin/activity", icon: Activity, color: "text-teal-600" },
        ],
    },
];

function useIsActive(href: string) {
    const pathname = usePathname() ?? "";
    if (href === "/dashboard") return pathname === "/dashboard";
    return pathname === href || pathname.startsWith(href + "/");
}

function NavLink({ item, isCollapsed }: { item: NavItem; isCollapsed: boolean }) {
    const isActive = useIsActive(item.href);
    const { t } = useTranslation();

    return (
        <Link
            href={item.href}
            className={cn(
                "group relative flex items-center gap-3 py-2.5 rounded-xl transition-all duration-200 outline-none focus-visible:ring-2 focus-visible:ring-primary/40",
                isCollapsed ? "justify-center px-2.5" : "px-3",
                isActive
                    ? "bg-white shadow-sm border border-white/80 text-primary"
                    : "text-ink/55 hover:text-ink/85 hover:bg-white/50 border border-transparent"
            )}
        >
            {/* Active indicator pill */}
            {isActive && (
                <motion.span
                    layoutId="nav-active-pill"
                    className="absolute inset-y-2.5 rtl:right-0 ltr:left-0 w-[3px] rounded-full bg-primary"
                    transition={{ type: "spring", stiffness: 400, damping: 35 }}
                />
            )}

            {/* Icon */}
            <div
                className={cn(
                    "relative shrink-0 w-8 h-8 rounded-lg flex items-center justify-center transition-all duration-200",
                    isActive
                        ? "bg-primary/8 text-primary"
                        : "group-hover:bg-white group-hover:shadow-sm"
                )}
            >
                <item.icon
                    className={cn(
                        "w-[17px] h-[17px] transition-all duration-200",
                        isActive
                            ? "text-primary scale-110"
                            : cn("opacity-55 group-hover:opacity-100 group-hover:scale-105", item.color)
                    )}
                />

                {/* Collapsed tooltip — RTL: sidebar on right, label opens toward content (inline-end) */}
                {isCollapsed && (
                    <div
                        role="tooltip"
                        className={cn(
                            "pointer-events-none absolute top-1/2 -translate-y-1/2 z-[200]",
                            "px-3 py-1.5 bg-ink/90 text-white text-[10.5px] font-bold rounded-lg shadow-xl",
                            "whitespace-nowrap backdrop-blur-sm font-vazirmatn",
                            "opacity-0 scale-95 group-hover:opacity-100 group-hover:scale-100",
                            "transition-all duration-150 origin-[inline-start]",
                            "end-full me-2.5"
                        )}
                    >
                        {t(item.titleKey)}
                        <span
                            aria-hidden
                            className="absolute top-1/2 -translate-y-1/2 start-0 translate-x-1/2 w-2 h-2 bg-ink/90 rotate-45"
                        />
                    </div>
                )}
            </div>

            {/* Label */}
            {!isCollapsed && (
                <span
                    className={cn(
                        "font-vazirmatn font-bold text-[11.5px] tracking-wide flex-1 truncate transition-colors duration-200",
                        isActive ? "text-primary" : "text-ink/65 group-hover:text-ink/90"
                    )}
                >
                    {t(item.titleKey)}
                </span>
            )}

            {/* Active dot */}
            {!isCollapsed && isActive && (
                <motion.span
                    layoutId="nav-active-dot"
                    className="w-1.5 h-1.5 rounded-full bg-primary/50 shrink-0"
                    transition={{ type: "spring", stiffness: 400, damping: 35 }}
                />
            )}

        </Link>
    );
}

export function Sidebar() {
    const { user, logout } = useAuth();
    const { t } = useTranslation();
    const { language, switchLocale } = useLocaleSwitch();
    const prefersReduced = useReducedMotion();

    const [isCollapsed, setIsCollapsed] = React.useState(() => {
        if (typeof window === "undefined") return false;
        return localStorage.getItem("sidebar-collapsed") === "true";
    });

    useEffect(() => {
        localStorage.setItem("sidebar-collapsed", String(isCollapsed));
    }, [isCollapsed]);

    const filterItems = (items: NavItem[]) =>
        items.filter((item) => canAccessRoute(user?.role, item.href));

    const glowProps = prefersReduced
        ? {}
        : {
              animate: { scale: [1, 1.08, 1], opacity: [0.35, 0.55, 0.35] },
              transition: { duration: 10, repeat: Infinity, ease: "easeInOut" as const },
          };

    return (
        <aside
            className={cn(
                "hidden md:flex flex-col h-screen bg-white/55 backdrop-blur-2xl transition-[width] duration-300 ease-out relative z-50 overflow-visible",
                "border-e border-white/70 shadow-[4px_0_24px_rgba(0,0,0,0.04)]",
                isCollapsed ? "w-[68px]" : "w-[232px]"
            )}
        >
            {/* Ambient glows */}
            <motion.div
                {...glowProps}
                className="absolute top-[12%] -start-8 w-44 h-44 bg-primary/6 rounded-full blur-[80px] pointer-events-none"
            />
            <motion.div
                {...(prefersReduced ? {} : {
                    animate: { scale: [1.08, 1, 1.08], opacity: [0.25, 0.45, 0.25] },
                    transition: { duration: 13, repeat: Infinity, ease: "easeInOut" as const },
                })}
                className="absolute bottom-[20%] -end-8 w-52 h-52 bg-indigo-400/5 rounded-full blur-[100px] pointer-events-none"
            />

            {/* Logo */}
            <div
                className={cn(
                    "h-[60px] flex items-center shrink-0 relative z-10 border-b border-ink/[0.04] transition-all overflow-visible",
                    isCollapsed ? "justify-center px-0" : "px-5"
                )}
            >
                <Link
                    href="/dashboard"
                    className={cn("flex items-center gap-3 group/logo min-w-0", isCollapsed ? "justify-center" : "w-full")}
                >
                    <div className="w-10 h-10 relative shrink-0 overflow-visible">
                        <div className="absolute inset-0 bg-primary/15 rounded-xl blur-lg opacity-0 group-hover/logo:opacity-100 transition-opacity duration-500" />
                        <div className="relative w-full h-full bg-gradient-to-br from-white to-white/90 rounded-xl flex items-center justify-center border border-white shadow-sm transition-transform duration-300 group-hover/logo:scale-105 p-2 overflow-visible">
                            <Image src="/logo-3.svg" alt="Logo" width={24} height={24} className="w-full h-full object-contain" />
                        </div>
                    </div>

                    <AnimatePresence initial={false}>
                        {!isCollapsed && (
                            <motion.div
                                key="logo-text"
                                initial={{ opacity: 0, width: 0 }}
                                animate={{ opacity: 1, width: "auto" }}
                                exit={{ opacity: 0, width: 0 }}
                                transition={{ duration: 0.2, ease: "easeOut" as const }}
                                className="flex flex-col overflow-hidden min-w-0 flex-1"
                            >
                                <span className="font-vazirmatn font-black text-[13.5px] tracking-tight text-ink leading-none whitespace-nowrap">
                                    {t("common.appName")}
                                </span>
                                
                            </motion.div>
                        )}
                    </AnimatePresence>
                </Link>
            </div>

            {/* Navigation */}
            <nav
                className={cn(
                    "flex-1 py-3 space-y-6 overflow-y-auto overflow-x-visible relative z-10 scrollbar-hide transition-all duration-300",
                    isCollapsed ? "px-2" : "px-3.5"
                )}
            >
                {NAV_GROUPS.map((group, idx) => {
                    const items = filterItems(group.items);
                    if (items.length === 0) return null;

                    return (
                        <div key={idx} className="space-y-1">
                            <AnimatePresence initial={false}>
                                {!isCollapsed && (
                                    <motion.h3
                                        key={`group-label-${idx}`}
                                        initial={{ opacity: 0 }}
                                        animate={{ opacity: 1 }}
                                        exit={{ opacity: 0 }}
                                        transition={{ duration: 0.15 }}
                                        className="px-3 mb-2 text-[9px] font-black text-ink/25 uppercase tracking-[0.13em]"
                                    >
                                        {t(group.titleKey)}
                                    </motion.h3>
                                )}
                            </AnimatePresence>

                            {isCollapsed && idx > 0 && (
                                <div className="my-2 h-px bg-ink/5 mx-1" />
                            )}

                            <div className="space-y-0.5">
                                {items.map((item) => (
                                    <NavLink key={item.href} item={item} isCollapsed={isCollapsed} />
                                ))}
                            </div>
                        </div>
                    );
                })}
            </nav>

            {/* Footer */}
            <div
                className={cn(
                    "shrink-0 relative z-10 border-t border-ink/[0.05] bg-gradient-to-t from-white/20 to-transparent transition-all duration-300",
                    isCollapsed ? "p-2" : "p-3"
                )}
            >
                {/* Controls row: language toggle + collapse button */}
                <div
                    className={cn(
                        "flex items-center mb-3",
                        isCollapsed ? "flex-col gap-2 items-center" : "justify-between px-1"
                    )}
                >
                    {/* Language toggle */}
                    <button
                        onClick={() => switchLocale()}
                        title={t("common.language")}
                        className={cn(
                            "group flex items-center gap-1.5 rounded-lg transition-all duration-200 text-ink/40 hover:text-primary hover:bg-white hover:shadow-sm border border-transparent hover:border-white",
                            isCollapsed ? "p-1.5 justify-center" : "px-2.5 py-1.5"
                        )}
                    >
                        <Globe className="w-3.5 h-3.5 shrink-0" />
                        <AnimatePresence initial={false}>
                            {!isCollapsed && (
                                <motion.span
                                    key="lang-label"
                                    initial={{ opacity: 0, width: 0 }}
                                    animate={{ opacity: 1, width: "auto" }}
                                    exit={{ opacity: 0, width: 0 }}
                                    className="text-[10px] font-black overflow-hidden whitespace-nowrap"
                                >
                                    {language === "ar" ? t("common.arabic") : t("common.persian")}
                                </motion.span>
                            )}
                        </AnimatePresence>
                    </button>

                    {/* Collapse toggle */}
                    <button
                        onClick={() => setIsCollapsed((c) => !c)}
                        title={isCollapsed ? t("nav.expand") : t("nav.collapse")}
                        className="p-1.5 rounded-lg text-ink/35 hover:text-primary hover:bg-white hover:shadow-sm border border-transparent hover:border-white transition-all duration-200 active:scale-90"
                    >
                        <ChevronRight
                            className={cn(
                                "w-3.5 h-3.5 transition-transform duration-300 ease-out rtl:rotate-180",
                                isCollapsed ? "" : "rotate-180 rtl:rotate-0"
                            )}
                        />
                    </button>
                </div>

                {/* User card */}
                <AnimatePresence initial={false}>
                    {!isCollapsed && user && (
                        <motion.div
                            key="user-card"
                            initial={{ opacity: 0, y: 6 }}
                            animate={{ opacity: 1, y: 0 }}
                            exit={{ opacity: 0, y: 6 }}
                            transition={{ duration: 0.2 }}
                            className="flex items-center gap-2.5 px-3 py-2 mb-1.5 rounded-xl bg-white/40 border border-white/60 hover:bg-white/70 transition-colors cursor-default"
                        >
                            <div className="w-7 h-7 rounded-lg bg-gradient-to-br from-primary/10 to-primary/5 border border-primary/10 flex items-center justify-center shrink-0">
                                <ShieldCheck className="w-3.5 h-3.5 text-primary/60" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-[11px] font-black text-ink truncate leading-none mb-1">{user.name}</p>
                                <div className="flex items-center gap-1.5">
                                    <span
                                        className={cn(
                                            "w-1.5 h-1.5 rounded-full shrink-0",
                                            user.role === "admin" ? "bg-primary" : "bg-purple-400"
                                        )}
                                    />
                                    <span className="text-[8.5px] font-black uppercase tracking-wider text-ink/40 truncate">
                                        {t(`roles.${user.role}`)}
                                    </span>
                                </div>
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                {/* Logout */}
                <button
                    onClick={logout}
                    className={cn(
                        "w-full flex items-center gap-2.5 py-2 rounded-xl transition-all duration-200 group/logout hover:bg-rose-50 border border-transparent hover:border-rose-100 outline-none focus-visible:ring-2 focus-visible:ring-rose-400/30",
                        isCollapsed ? "justify-center px-0" : "px-3"
                    )}
                >
                    <div className="w-7 h-7 rounded-lg flex items-center justify-center transition-all duration-200 group-hover/logout:bg-white group-hover/logout:shadow-sm shrink-0">
                        <LogOut className="w-3.5 h-3.5 text-ink/35 group-hover/logout:text-rose-500 transition-colors" />
                    </div>
                    <AnimatePresence initial={false}>
                        {!isCollapsed && (
                            <motion.span
                                key="logout-label"
                                initial={{ opacity: 0, width: 0 }}
                                animate={{ opacity: 1, width: "auto" }}
                                exit={{ opacity: 0, width: 0 }}
                                className="font-vazirmatn font-bold text-[11px] text-ink/50 group-hover/logout:text-rose-500 uppercase tracking-wide transition-colors overflow-hidden whitespace-nowrap"
                            >
                                {t("nav.logout")}
                            </motion.span>
                        )}
                    </AnimatePresence>
                </button>
            </div>
        </aside>
    );
}
