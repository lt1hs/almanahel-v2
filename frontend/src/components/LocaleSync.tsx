"use client";

import { useEffect } from "react";
import { useParams } from "next/navigation";
import { useLanguage, type Language } from "@/contexts/LanguageContext";

function parseLocale(value: unknown): Language | null {
  return value === "ar" || value === "fa" ? value : null;
}

export function LocaleSync({ children }: { children: React.ReactNode }) {
  const params = useParams();
  const { language, setLanguage, setCurrency } = useLanguage();
  const urlLocale = parseLocale(params?.locale);

  useEffect(() => {
    if (!urlLocale || urlLocale === language) return;
    setLanguage(urlLocale);
    // Language switch resets currency to the locale default
    setCurrency(urlLocale === "ar" ? "IQD" : "TOMAN");
  }, [urlLocale, language, setLanguage, setCurrency]);

  return <>{children}</>;
}
