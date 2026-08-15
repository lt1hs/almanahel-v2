"use client";

import React, { useEffect } from "react";
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { isIraqAccount } from "@/lib/userLocale";

export default function RootPage() {
    const { user, isLoading } = useAuth();
    const router = useRouter();

    useEffect(() => {
        if (!isLoading) {
            if (user) {
                router.push("/dashboard", {
                    locale: isIraqAccount(user) ? "ar" : undefined,
                });
            } else {
                router.push("/login");
            }
        }
    }, [user, isLoading, router]);

    return (
        <div className="flex h-screen items-center justify-center bg-parchment">
            <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-primary"></div>
        </div>
    );
}
