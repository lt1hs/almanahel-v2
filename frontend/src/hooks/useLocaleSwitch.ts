"use client";

import { useRouter, usePathname } from "@/i18n/routing";
import { useLanguage, type Language } from "@/contexts/LanguageContext";

export function useLocaleSwitch() {
  const router = useRouter();
  const pathname = usePathname();
  const { language } = useLanguage();

  const switchLocale = (next?: Language) => {
    const target = next ?? (language === "ar" ? "fa" : "ar");
    router.replace(pathname, { locale: target });
  };

  return { language, switchLocale };
}
