"use client";

import React, { useEffect } from "react";
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { useLanguage } from "@/contexts/LanguageContext";
import { Sidebar } from "@/components/layout/Sidebar";
import { Navbar } from "@/components/layout/Navbar";
import { MobileNav } from "@/components/layout/MobileNav";
import { Footer } from "@/components/layout/Footer";


export default function DashboardLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { user, isLoading } = useAuth();
    const { t } = useLanguage();
    const router = useRouter();

    useEffect(() => {
        if (!isLoading && !user) {
            router.push("/login");
        }
    }, [user, isLoading, router]);

    if (isLoading) {
        return (
            <div className="flex h-screen items-center justify-center bg-parchment">
                <div className="flex flex-col items-center gap-4">
                    <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
                    <p className="font-vazirmatn text-ink/60">{t("common.loading")}</p>
                </div>
            </div>
        );
    }

    if (!user) {
        return null;
    }

    return (
        <div className="flex h-screen overflow-hidden bg-parchment">
            <Sidebar />

            <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
                <Navbar />

                <main className="flex-1 overflow-y-auto">
                    <div className="max-w-7xl mx-auto p-4 md:p-6 pb-24 md:pb-10">
                        {children}
                    </div>
                </main>

                <Footer />

                <MobileNav />

            </div>
        </div>
    );
}
