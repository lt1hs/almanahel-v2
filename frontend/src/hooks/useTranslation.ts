import { useMemo } from "react";
import { useLanguage } from "@/contexts/LanguageContext";

export function useTranslation() {
  const { t, tn, formatNumber, formatCurrency, formatDate, language, currency, setCurrency } =
    useLanguage();

  return useMemo(
    () => ({
      t,
      tn,
      formatNumber,
      formatCurrency,
      formatDate,
      language,
      currency,
      setCurrency,
      isArabic: language === "ar",
      isFarsi: language === "fa",
    }),
    [t, tn, formatNumber, formatCurrency, formatDate, language, currency, setCurrency]
  );
}
