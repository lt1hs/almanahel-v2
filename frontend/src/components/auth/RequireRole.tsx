"use client";

import React, { useEffect } from "react";
import { ShieldAlert } from "lucide-react";
import { useAuth, type UserRole } from "@/contexts/AuthContext";
import { useRouter } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";

interface RequireRoleProps {
    roles: UserRole[];
    children: React.ReactNode;
    /** i18n key for the forbidden message (default: activityLog.forbidden) */
    messageKey?: string;
}

/**
 * Page-level role gate — redirects unauthorized users to the dashboard
 * and shows a short forbidden state while redirecting.
 */
export function RequireRole({ roles, children, messageKey = "activityLog.forbidden" }: RequireRoleProps) {
    const { user, isLoading } = useAuth();
    const router = useRouter();
    const { t } = useTranslation();
    const allowed = Boolean(user && roles.includes(user.role));

    useEffect(() => {
        if (isLoading) return;
        if (!user || !allowed) {
            router.replace("/dashboard");
        }
    }, [isLoading, user, allowed, router]);

    if (isLoading) {
        return (
            <div className="flex items-center justify-center py-24">
                <div className="h-8 w-8 rounded-full border-2 border-primary/30 border-t-primary animate-spin" />
            </div>
        );
    }

    if (!allowed) {
        return (
            <div className="flex flex-col items-center justify-center py-24 gap-3 text-ink/40">
                <ShieldAlert className="w-8 h-8" />
                <p className="text-sm font-black font-vazirmatn">{t(messageKey)}</p>
            </div>
        );
    }

    return <>{children}</>;
}
