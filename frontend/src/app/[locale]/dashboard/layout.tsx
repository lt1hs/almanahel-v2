"use client";

import React, { useEffect } from "react";
import { ShieldAlert } from "lucide-react";
import { usePathname, useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { useLanguage } from "@/contexts/LanguageContext";
import { Sidebar } from "@/components/layout/Sidebar";
import { Navbar } from "@/components/layout/Navbar";
import { MobileNav } from "@/components/layout/MobileNav";
import { Footer } from "@/components/layout/Footer";
import { canAccessRoute } from "@/lib/routeAccess";
import { PageReadyGate } from "@/components/NavigationProgress";
import { PageLoader } from "@/components/ui/Loading";

export default function DashboardLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { user, isLoading } = useAuth();
    const { t } = useLanguage();
    const router = useRouter();
    const pathname = usePathname() ?? "";
    const allowed = Boolean(user && canAccessRoute(user.role, pathname));

    useEffect(() => {
        if (isLoading || user) return;
        const token = localStorage.getItem("al-manahel-token");
        if (!token) {
            router.push("/login");
        }
    }, [user, isLoading, router]);

    useEffect(() => {
        if (isLoading || !user) return;
        if (!canAccessRoute(user.role, pathname)) {
            router.replace("/dashboard");
        }
    }, [isLoading, user, pathname, router]);

    if (isLoading) {
        return (
            <div className="flex h-screen items-center justify-center bg-parchment">
                <PageLoader />
            </div>
        );
    }

    if (!user) {
        return null;
    }

    if (!allowed) {
        return (
            <div className="flex h-screen overflow-hidden bg-parchment">
                <Sidebar />
                <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
                    <Navbar />
                    <main className="flex-1 overflow-y-auto">
                        <div className="flex flex-col items-center justify-center py-24 gap-3 text-ink/40">
                            <ShieldAlert className="w-8 h-8" />
                            <p className="text-sm font-black font-vazirmatn">{t("activityLog.forbidden")}</p>
                        </div>
                    </main>
                </div>
            </div>
        );
    }

    return (
        <div className="flex h-screen overflow-hidden bg-parchment">
            <Sidebar />

            <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
                <Navbar />

                <main className="flex-1 overflow-y-auto">
                    <div className="max-w-7xl mx-auto p-4 md:p-6 pb-24 md:pb-10">
                        <PageReadyGate key={pathname}>{children}</PageReadyGate>
                    </div>
                </main>

                <Footer />

                <MobileNav />

            </div>
        </div>
    );
}
