import { useMemo } from "react";
import { useLanguage } from "@/contexts/LanguageContext";

export function useTranslation() {
  const { t, tn, formatNumber, formatCurrency, formatDate, language, currency, setCurrency } =
    useLanguage();

  return useMemo(() => {
    const isDinar = currency === "IQD";
    return {
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
      /** Navbar currency toggle — use this for money, not isArabic */
      isDinar,
      preferredCurrency: (isDinar ? "dinar" : "toman") as "dinar" | "toman",
      currencySymbolKey: isDinar
        ? "common.currency.dinarSymbol"
        : "common.currency.tomanSymbol",
      currencyNameKey: isDinar ? "common.dinar" : "common.toman",
    };
  }, [t, tn, formatNumber, formatCurrency, formatDate, language, currency, setCurrency]);
}
