/**
 * Pro-Level i18n Usage Examples
 * 
 * This file demonstrates all the advanced multilingual features available
 */

import { useTranslation } from "@/hooks/useTranslation";

export function I18nExamples() {
  const { t, tn, formatNumber, formatCurrency, formatDate, isArabic } = useTranslation();

  // 1. Basic translation
  const title = t("dashboard.title");

  // 2. Translation with interpolation (variables)
  const fieldError = t("validation.fieldRequired", { field: "Email" });
  const minLengthError = t("validation.minLength", { field: "Password", min: 8 });

  // 3. Pluralization
  const bookCount0 = tn("plurals.book", 0); // "هیچ کتابی وجود ندارد"
  const bookCount1 = tn("plurals.book", 1); // "یک کتاب"
  const bookCount5 = tn("plurals.book", 5); // "5 کتاب"

  // 4. Number formatting (locale-aware)
  const formattedNumber = formatNumber(1234567); // "۱٬۲۳۴٬۵۶۷" (Farsi) or "١٬٢٣٤٬٥٦٧" (Arabic)

  // 5. Currency formatting
  const price = formatCurrency(50000); // "۵۰٬۰۰۰ ﷼" (Farsi) or "٥٠٬٠٠٠ ع.د" (Arabic)

  // 6. Date formatting (locale-aware)
  const formattedDate = formatDate(new Date()); // Persian/Arabic calendar format

  // 7. Conditional rendering based on language
  const currencySymbol = isArabic ? "ع.د" : "﷼";

  return (
    <div className="p-6 space-y-4">
      <h1>{title}</h1>
      
      {/* Basic translation */}
      <p>{t("common.welcome")}</p>

      {/* With interpolation */}
      <p className="text-red-500">{fieldError}</p>
      <p className="text-red-500">{minLengthError}</p>

      {/* Pluralization */}
      <div>
        <p>{bookCount0}</p>
        <p>{bookCount1}</p>
        <p>{bookCount5}</p>
      </div>

      {/* Number formatting */}
      <p>{t("common.total")}: {formattedNumber}</p>

      {/* Currency formatting */}
      <p>{t("common.price")}: {price}</p>

      {/* Date formatting */}
      <p>{t("common.date")}: {formattedDate}</p>

      {/* Language-specific content */}
      <p>{currencySymbol}</p>
    </div>
  );
}

/**
 * Usage in your components:
 * 
 * import { useTranslation } from "@/hooks/useTranslation";
 * 
 * function MyComponent() {
 *   const { t, formatCurrency } = useTranslation();
 *   
 *   return (
 *     <div>
 *       <h1>{t("inventory.title")}</h1>
 *       <p>{formatCurrency(price)}</p>
 *     </div>
 *   );
 * }
 */
