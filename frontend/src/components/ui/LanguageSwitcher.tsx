"use client";

import React from "react";
import { Globe } from "lucide-react";
import { useTranslation } from "@/hooks/useTranslation";
import { useLocaleSwitch } from "@/hooks/useLocaleSwitch";

export function LanguageSwitcher() {
  const { t } = useTranslation();
  const { language, switchLocale } = useLocaleSwitch();

  return (
    <button
      onClick={() => switchLocale()}
      className="flex items-center gap-2 px-3 py-2 rounded-[7px] hover:bg-parchment/10 transition-colors text-parchment/80 hover:text-parchment"
      title={t("common.language")}
    >
      <Globe className="w-5 h-5" />
      <span className="text-sm font-medium">
        {language === "ar" ? t("common.arabic") : t("common.persian")}
      </span>
    </button>
  );
}
