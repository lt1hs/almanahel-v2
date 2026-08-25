"use client";

import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";

export function PageLoader({ className }: { className?: string }) {
  const { t } = useTranslation();

  return (
    <div
      className={cn("flex flex-col items-center justify-center gap-4", className)}
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div className="h-12 w-12 animate-spin rounded-full border-t-2 border-b-2 border-primary" />
      <p className="font-ibm-plex-arabic text-ink/60">{t("common.loading")}</p>
    </div>
  );
}
