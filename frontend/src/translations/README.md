# Pro-Level Multilingual System (Arabic/Farsi RTL)

## Features

### 1. **Translation with Variable Interpolation**
```tsx
t("validation.fieldRequired", { field: "Email" })
// Output: "فیلد Email الزامی است"

t("validation.minLength", { field: "Password", min: 8 })
// Output: "Password باید حداقل 8 کاراکتر باشد"
```

### 2. **Pluralization Support**
```tsx
tn("plurals.book", 0)  // "هیچ کتابی وجود ندارد"
tn("plurals.book", 1)  // "یک کتاب"
tn("plurals.book", 5)  // "5 کتاب"
```

### 3. **Locale-Aware Number Formatting**
```tsx
formatNumber(1234567)
// Farsi: "۱٬۲۳۴٬۵۶۷"
// Arabic: "١٬٢٣٤٬٥٦٧"
```

### 4. **Currency Formatting**
```tsx
formatCurrency(50000)
// Farsi: "۵۰٬۰۰۰ ﷼"
// Arabic: "٥٠٬٠٠٠ ع.د"
```

### 5. **Date Formatting**
```tsx
formatDate(new Date())
// Uses Persian calendar for Farsi
// Uses Arabic calendar for Arabic
```

### 6. **Missing Translation Tracking**
Development mode logs missing translations to console for easy debugging.

### 7. **Type-Safe Translations**
Full TypeScript support with autocomplete for translation keys.

## Usage

### Basic Setup
```tsx
import { useTranslation } from "@/hooks/useTranslation";

function MyComponent() {
  const { t, formatCurrency, language } = useTranslation();
  
  return (
    <div>
      <h1>{t("dashboard.title")}</h1>
      <p>{formatCurrency(price)}</p>
    </div>
  );
}
```

### Advanced Usage
```tsx
import { useTranslation } from "@/hooks/useTranslation";

function BookList({ books }: { books: Book[] }) {
  const { t, tn, formatNumber, isArabic } = useTranslation();
  
  return (
    <div>
      <h2>{tn("plurals.book", books.length)}</h2>
      {books.map(book => (
        <div key={book.id}>
          <h3>{book.title}</h3>
          <p>{t("common.price")}: {formatNumber(book.price)}</p>
        </div>
      ))}
    </div>
  );
}
```

### Form Validation
```tsx
function validateForm(data: FormData) {
  const { t } = useTranslation();
  const errors: string[] = [];
  
  if (!data.email) {
    errors.push(t("validation.fieldRequired", { field: t("common.email") }));
  }
  
  if (data.password.length < 8) {
    errors.push(t("validation.minLength", { field: t("auth.password"), min: 8 }));
  }
  
  return errors;
}
```

## Translation File Structure

```json
{
  "common": {
    "appName": "دارالمناهل",
    "save": "ذخیره"
  },
  "plurals": {
    "book": {
      "zero": "هیچ کتابی وجود ندارد",
      "one": "یک کتاب",
      "other": "{{count}} کتاب"
    }
  },
  "validation": {
    "fieldRequired": "فیلد {{field}} الزامی است"
  }
}
```

## API Reference

### `useTranslation()`
Returns an object with:

- `t(key, params?)` - Translate with optional variable interpolation
- `tn(key, count, params?)` - Translate with pluralization
- `formatNumber(num)` - Format number for current locale
- `formatCurrency(amount)` - Format currency with symbol
- `formatDate(date)` - Format date for current locale
- `language` - Current language ("ar" | "fa")
- `isArabic` - Boolean flag for Arabic
- `isFarsi` - Boolean flag for Farsi

### `useLanguage()`
Lower-level hook with additional features:

- `setLanguage(lang)` - Change language
- `dir` - Text direction (always "rtl")
- `isLoading` - Loading state

## Best Practices

1. **Always use formatCurrency() for prices** instead of manual formatting
2. **Use tn() for countable items** to handle zero/one/many cases
3. **Use interpolation** instead of string concatenation
4. **Keep translation keys organized** by feature/module
5. **Test both languages** to ensure proper RTL layout

## Migration Guide

Replace manual formatting:
```tsx
// ❌ Old way
<p>{price.toLocaleString()} ﷼</p>

// ✅ New way
<p>{formatCurrency(price)}</p>
```

Replace string concatenation:
```tsx
// ❌ Old way
<p>{t("error")}: {fieldName} {t("required")}</p>

// ✅ New way
<p>{t("validation.fieldRequired", { field: fieldName })}</p>
```

Replace manual plurals:
```tsx
// ❌ Old way
<p>{count} {count === 1 ? "کتاب" : "کتابها"}</p>

// ✅ New way
<p>{tn("plurals.book", count)}</p>
```
