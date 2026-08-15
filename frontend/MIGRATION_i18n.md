# i18n Migration Guide for Remaining Pages

## Pages that need updates:

### 1. `/app/dashboard/admin/page.tsx`
**Add at top:**
```tsx
import { useTranslation } from "@/hooks/useTranslation";
```

**Add in component:**
```tsx
const { t } = useTranslation();
```

**Replace hardcoded text:**
- `"پنل مدیریت سیستم"` → `{t("admin.title")}`
- `"مدیریت کاربران و نقشها"` → `{t("admin.users")}`
- `"مدیریت شعب"` → `{t("admin.branches")}`
- `"تأمینکنندگان"` → `{t("admin.suppliers")}`

---

### 2. `/app/dashboard/distribution/page.tsx`
**Add at top:**
```tsx
import { useTranslation } from "@/hooks/useTranslation";
```

**Add in component:**
```tsx
const { t, formatNumber } = useTranslation();
```

**Replace:**
- `"توزیع و جابجایی کالا"` → `{t("distribution.title")}`
- `"انتقال جدید"` → `{t("distribution.newTransfer")}`
- `"از منبع"` → `{t("distribution.fromBranch")}`
- `"به مقصد"` → `{t("distribution.toBranch")}`
- Numbers like `۴۵۰` → `{formatNumber(450)}`

---

### 3. `/app/dashboard/finance/page.tsx`
**Add at top:**
```tsx
import { useTranslation } from "@/hooks/useTranslation";
```

**Add in component:**
```tsx
const { t, formatCurrency } = useTranslation();
```

**Replace:**
- `"گزارشات مالی و تسویه"` → `{t("finance.title")}`
- `"سود ناخالص"` → `{t("finance.profit")}`
- `"درآمد"` → `{t("finance.revenue")}`
- `"هزینهها"` → `{t("finance.expenses")}`
- `"تسویه"` → `{t("finance.settlement")}`
- Currency amounts → `{formatCurrency(amount)}`

---

### 4. `/app/dashboard/inventory/page.tsx`
**Already has useLanguage, update to:**
```tsx
import { useTranslation } from "@/hooks/useTranslation";
const { t, formatCurrency } = useTranslation();
```

**Replace:**
- Line 102: `{book.price.toLocaleString()}` → `{formatCurrency(book.price)}`
- `"موجودی انبار و کتابها"` → `{t("inventory.title")}`
- `"افزودن کتاب"` → `{t("inventory.addBook")}`

---

### 5. `/app/dashboard/inventory/new/page.tsx`
**Add:**
```tsx
import { useTranslation } from "@/hooks/useTranslation";
const { t, formatCurrency, isArabic } = useTranslation();
```

**Replace:**
- Line 143: `{Number(formData.book.priceToman).toLocaleString()} ﷼` → `{formatCurrency(formData.book.priceToman)}`
- Line 147: `{Number(formData.book.priceDinar).toLocaleString()} ع.د` → `{formatCurrency(formData.book.priceDinar)}`
- Use `isArabic` to conditionally show Dinar vs Toman fields

---

### 6. `/app/dashboard/sales/page.tsx`
**Add:**
```tsx
import { useTranslation } from "@/hooks/useTranslation";
const { t, formatCurrency } = useTranslation();
```

**Replace:**
- Line 68: `{book.price.toLocaleString()} ﷼` → `{formatCurrency(book.price)}`
- `"فروش و صندوق"` → `{t("sales.title")}`
- `"سبد خرید"` → `{t("sales.cart")}`

---

## Quick Fix Script

Run this in each page component:

```tsx
// 1. Add import
import { useTranslation } from "@/hooks/useTranslation";

// 2. Add hook
const { t, formatCurrency, formatNumber } = useTranslation();

// 3. Replace all .toLocaleString() with formatNumber()
// 4. Replace all manual currency formatting with formatCurrency()
// 5. Replace hardcoded strings with t("key")
```

---

## Priority Order:
1. ✅ dashboard/page.tsx (already done)
2. ✅ login/page.tsx (already done)  
3. **inventory/page.tsx** - High priority (has currency)
4. **sales/page.tsx** - High priority (has currency)
5. **inventory/new/page.tsx** - High priority (has currency)
6. **finance/page.tsx** - Medium priority
7. **distribution/page.tsx** - Medium priority
8. **admin/page.tsx** - Low priority

---

## Testing Checklist:
- [ ] Switch between Arabic/Farsi
- [ ] Verify currency symbols (﷼ for Farsi, ع.د for Arabic)
- [ ] Check number formatting (Persian vs Arabic numerals)
- [ ] Verify all text is translated
- [ ] Check RTL layout
