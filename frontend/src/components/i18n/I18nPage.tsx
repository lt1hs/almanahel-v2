/**
 * Quick i18n Wrapper Component
 * 
 * Use this to quickly add i18n support to any page without refactoring
 * 
 * Usage:
 * 1. Wrap your page content with <I18nPage>
 * 2. Access t, formatCurrency, formatNumber via props
 * 
 * Example:
 * <I18nPage>
 *   {({ t, formatCurrency }) => (
 *     <div>
 *       <h1>{t("inventory.title")}</h1>
 *       <p>{formatCurrency(price)}</p>
 *     </div>
 *   )}
 * </I18nPage>
 */

import { useTranslation } from "@/hooks/useTranslation";
import { ReactNode } from "react";

interface I18nPageProps {
  children: (helpers: ReturnType<typeof useTranslation>) => ReactNode;
}

export function I18nPage({ children }: I18nPageProps) {
  const helpers = useTranslation();
  return <>{children(helpers)}</>;
}

// Alternative: HOC approach
export function withI18n<P extends object>(
  Component: React.ComponentType<P & { i18n: ReturnType<typeof useTranslation> }>
) {
  return function WithI18nComponent(props: P) {
    const i18n = useTranslation();
    return <Component {...props} i18n={i18n} />;
  };
}
