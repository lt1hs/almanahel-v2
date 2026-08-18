"use client";

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";
import { useMessages } from "next-intl";

export type Language = "ar" | "fa";
export type Currency = "IQD" | "TOMAN";

interface Translations {
  [key: string]: string | Translations;
}

interface LanguageContextType {
  language: Language;
  setLanguage: (lang: Language) => void;
  currency: Currency;
  setCurrency: (currency: Currency) => void;
  t: (key: string, params?: Record<string, string | number>) => string;
  tn: (key: string, count: number, params?: Record<string, string | number>) => string;
  formatNumber: (num: number) => string;
  formatCurrency: (amount: number) => string;
  formatDate: (date: Date | string) => string;
  dir: "rtl";
  isLoading: boolean;
}

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

function defaultCurrencyForLocale(locale: Language): Currency {
  return locale === "ar" ? "IQD" : "TOMAN";
}

function readStoredCurrency(fallback: Currency): Currency {
  if (typeof window === "undefined") return fallback;
  const saved = localStorage.getItem("al-manahel-currency");
  if (saved === "IQD" || saved === "TOMAN") return saved;
  return fallback;
}

export function LanguageProvider({
  children,
  initialLocale = "fa",
}: {
  children: React.ReactNode;
  initialLocale?: Language;
}) {
  // Current locale messages only (from NextIntlClientProvider) — avoids bundling both JSONs
  const messages = useMessages() as Translations;
  const language = initialLocale;
  const [currency, setCurrencyState] = useState<Currency>(() =>
    readStoredCurrency(defaultCurrencyForLocale(initialLocale))
  );
  const isLoading = false;

  useEffect(() => {
    localStorage.setItem("al-manahel-language", initialLocale);
    document.documentElement.lang = initialLocale;
  }, [initialLocale]);

  const setLanguage = useCallback((lang: Language) => {
    localStorage.setItem("al-manahel-language", lang);
    document.documentElement.lang = lang;
  }, []);

  const setCurrency = useCallback((curr: Currency) => {
    setCurrencyState(curr);
    localStorage.setItem("al-manahel-currency", curr);
  }, []);

  const t = useCallback(
    (key: string, params?: Record<string, string | number>): string => {
      const keys = key.split(".");
      let result: string | Translations = messages;

      for (const k of keys) {
        if (result && typeof result === "object" && k in result) {
          result = result[k];
        } else {
          if (process.env.NODE_ENV === "development") {
            console.warn(`[i18n] Missing translation: ${key} (${language})`);
          }
          return key;
        }
      }

      let translation = typeof result === "string" ? result : key;

      if (params) {
        Object.entries(params).forEach(([param, value]) => {
          translation = translation.replace(
            new RegExp(`{{${param}}}`, "g"),
            String(value)
          );
        });
      }

      return translation;
    },
    [messages, language]
  );

  const tn = useCallback(
    (key: string, count: number, params?: Record<string, string | number>): string => {
      const pluralKey =
        count === 0
          ? `${key}.zero`
          : count === 1
            ? `${key}.one`
            : count === 2
              ? `${key}.two`
              : `${key}.other`;
      const translation = t(pluralKey, { ...params, count });
      return translation === pluralKey ? t(key, { ...params, count }) : translation;
    },
    [t]
  );

  const formatNumber = useCallback(
    (num: number): string => {
      const locale = language === "ar" ? "ar-IQ" : "fa-IR";
      return new Intl.NumberFormat(locale).format(num);
    },
    [language]
  );

  const formatCurrency = useCallback(
    (amount: number): string => {
      const formatted = formatNumber(amount);
      return currency === "IQD"
        ? `${formatted} ${t("common.dinar")}`
        : `${formatted} ${t("common.toman")}`;
    },
    [currency, formatNumber, t]
  );

  const formatDate = useCallback(
    (date: Date | string): string => {
      const d = typeof date === "string" ? new Date(date) : date;
      const locale = language === "ar" ? "ar-IQ" : "fa-IR";
      return new Intl.DateTimeFormat(locale, {
        year: "numeric",
        month: "long",
        day: "numeric",
      }).format(d);
    },
    [language]
  );

  const value = useMemo(
    () => ({
      language,
      setLanguage,
      currency,
      setCurrency,
      t,
      tn,
      formatNumber,
      formatCurrency,
      formatDate,
      dir: "rtl" as const,
      isLoading,
    }),
    [
      language,
      setLanguage,
      currency,
      setCurrency,
      t,
      tn,
      formatNumber,
      formatCurrency,
      formatDate,
      isLoading,
    ]
  );

  return (
    <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>
  );
}

export function useLanguage() {
  const context = useContext(LanguageContext);
  if (context === undefined) {
    throw new Error("useLanguage must be used within a LanguageProvider");
  }
  return context;
}
