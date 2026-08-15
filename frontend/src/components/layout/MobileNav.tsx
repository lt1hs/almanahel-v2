"use client";

import React from "react";
import { Link, usePathname } from "@/i18n/routing";
import {
    LayoutDashboard,
    Library,
    Truck,
    Wallet,
    Menu
} from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";

export function MobileNav() {
    const pathname = usePathname();
    const { t } = useTranslation();

    const items = [
        { titleKey: "nav.dashboard", href: "/dashboard", icon: LayoutDashboard },
        { titleKey: "nav.inventory", href: "/dashboard/inventory", icon: Library },
        { titleKey: "nav.distribution", href: "/dashboard/distribution", icon: Truck },
        { titleKey: "nav.sales", href: "/dashboard/sales", icon: Wallet },
    ];

    return (
        <nav className="md:hidden fixed bottom-0 left-0 right-0 bg-ink border-t border-parchment/10 h-16 flex items-center justify-around px-4 z-50">
            {items.map((item) => {
                const isActive = pathname === item.href || pathname.startsWith(item.href + "/");
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        className={cn(
                            "flex flex-col items-center gap-1 transition-colors",
                            isActive ? "text-primary" : "text-parchment/40"
                        )}
                    >
                        <item.icon className="w-5 h-5" />
                        <span className="text-[10px] font-vazirmatn">{t(item.titleKey)}</span>
                    </Link>
                );
            })}
            <button className="flex flex-col items-center gap-1 text-parchment/40">
                <Menu className="w-5 h-5" />
                <span className="text-[10px] font-vazirmatn">{t("common.more")}</span>
            </button>
        </nav>
    );
}
