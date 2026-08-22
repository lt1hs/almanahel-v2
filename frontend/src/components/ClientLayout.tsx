"use client";

import { LanguageProvider, useLanguage, type Language } from "@/contexts/LanguageContext";
import { LocaleSync } from "@/components/LocaleSync";
import { useEffect } from "react";

function LanguageHandler({ children }: { children: React.ReactNode }) {
  const { language, dir } = useLanguage();

  useEffect(() => {
    document.documentElement.lang = language;
    document.documentElement.dir = dir;
  }, [language, dir]);

  return <>{children}</>;
}

function parseLocale(value?: string): Language {
  return value === "ar" ? "ar" : "fa";
}

export default function ClientLayout({
  children,
  locale,
}: {
  children: React.ReactNode;
  locale?: string;
}) {
  const initialLocale = parseLocale(locale);

  return (
    <LanguageProvider initialLocale={initialLocale}>
      <LocaleSync locale={initialLocale}>
        <LanguageHandler>{children}</LanguageHandler>
      </LocaleSync>
    </LanguageProvider>
  );
}
