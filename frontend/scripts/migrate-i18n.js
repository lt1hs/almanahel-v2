#!/usr/bin/env node

/**
 * Auto-migrate pages to use i18n
 * 
 * Usage: node scripts/migrate-i18n.js
 */

const fs = require('fs');
const path = require('path');

const replacements = {
  // Currency formatting
  /(\w+)\.toLocaleString\(\)\s*﷼/g: 'formatCurrency($1)',
  /(\w+)\.toLocaleString\(\)/g: 'formatNumber($1)',
  
  // Common translations
  '"موجودی انبار و کتابها"': '{t("inventory.title")}',
  '"افزودن کتاب"': '{t("inventory.addBook")}',
  '"فروش و صندوق"': '{t("sales.title")}',
  '"سبد خرید"': '{t("sales.cart")}',
  '"گزارشات مالی و تسویه"': '{t("finance.title")}',
  '"توزیع و جابجایی کالا"': '{t("distribution.title")}',
  '"پنل مدیریت سیستم"': '{t("admin.title")}',
};

const pagesDir = path.join(__dirname, '../src/app/dashboard');

function migrateFile(filePath) {
  let content = fs.readFileSync(filePath, 'utf8');
  let modified = false;

  // Add import if not exists
  if (!content.includes('useTranslation')) {
    content = content.replace(
      /"use client";\n/,
      '"use client";\n\nimport { useTranslation } from "@/hooks/useTranslation";\n'
    );
    modified = true;
  }

  // Apply replacements
  for (const [pattern, replacement] of Object.entries(replacements)) {
    const regex = typeof pattern === 'string' ? new RegExp(pattern, 'g') : pattern;
    if (regex.test(content)) {
      content = content.replace(regex, replacement);
      modified = true;
    }
  }

  if (modified) {
    fs.writeFileSync(filePath, content, 'utf8');
    console.log(`✅ Migrated: ${filePath}`);
  } else {
    console.log(`⏭️  Skipped: ${filePath}`);
  }
}

function walkDir(dir) {
  const files = fs.readdirSync(dir);
  
  files.forEach(file => {
    const filePath = path.join(dir, file);
    const stat = fs.statSync(filePath);
    
    if (stat.isDirectory()) {
      walkDir(filePath);
    } else if (file === 'page.tsx') {
      migrateFile(filePath);
    }
  });
}

console.log('🚀 Starting i18n migration...\n');
walkDir(pagesDir);
console.log('\n✨ Migration complete!');
console.log('\n⚠️  Manual steps required:');
console.log('1. Add const { t, formatCurrency, formatNumber } = useTranslation(); to each component');
console.log('2. Test language switching');
console.log('3. Verify currency formatting');
