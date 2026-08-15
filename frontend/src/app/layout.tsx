import type { ReactNode } from "react";

/**
 * Root shell required so `/` can redirect to the default locale.
 * Locale-specific lang/dir are applied client-side by ClientLayout.
 */
export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="fa" dir="rtl" suppressHydrationWarning>
      <body>{children}</body>
    </html>
  );
}
