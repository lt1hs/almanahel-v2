"use client";

import { useEffect } from "react";
import { useLanguage, type Language } from "@/contexts/LanguageContext";

function parseLocale(value: unknown): Language | null {
  return value === "ar" || value === "fa" ? value : null;
}

export function LocaleSync({
  children,
  locale,
}: {
  children: React.ReactNode;
  locale?: string;
}) {
  const { language, setLanguage, setCurrency } = useLanguage();
  const urlLocale = parseLocale(locale);

  useEffect(() => {
    if (!urlLocale || urlLocale === language) return;
    setLanguage(urlLocale);
    setCurrency(urlLocale === "ar" ? "IQD" : "TOMAN");
  }, [urlLocale, language, setLanguage, setCurrency]);

  return <>{children}</>;
}
