"use client";

import React, { useEffect } from "react";
import { useRouter } from "@/i18n/routing";
import { useAuth } from "@/contexts/AuthContext";
import { isIraqAccount } from "@/lib/userLocale";
import { PageLoader } from "@/components/ui/Loading";

export default function RootPage() {
    const { user, isLoading } = useAuth();
    const router = useRouter();

    useEffect(() => {
        if (isLoading) return;
        if (user) {
            router.push("/dashboard", {
                locale: isIraqAccount(user) ? "ar" : undefined,
            });
            return;
        }
        if (!localStorage.getItem("al-manahel-token")) {
            router.push("/login");
        }
    }, [user, isLoading, router]);

    return (
        <div className="flex h-screen items-center justify-center bg-parchment">
            <PageLoader />
        </div>
    );
}
