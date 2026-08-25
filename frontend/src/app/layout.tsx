import type { ReactNode } from "react";
import localFont from "next/font/local";

/**
 * Self-hosted IBM Plex Sans Arabic — must live on html/body so portaled
 * UI (modals, toasts, overlays) inherits the same face as the app shell.
 */
const ibmPlexSansArabic = localFont({
  src: [
    {
      path: "../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-400-normal.woff2",
      weight: "400",
      style: "normal",
    },
    {
      path: "../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-500-normal.woff2",
      weight: "500",
      style: "normal",
    },
    {
      path: "../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-600-normal.woff2",
      weight: "600",
      style: "normal",
    },
    {
      path: "../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-700-normal.woff2",
      weight: "700",
      style: "normal",
    },
  ],
  variable: "--font-ibm-plex-arabic-face",
  display: "swap",
  fallback: ["Segoe UI", "Tahoma", "system-ui", "sans-serif"],
});

/**
 * Root shell required so `/` can redirect to the default locale.
 * Locale-specific lang/dir are applied client-side by ClientLayout.
 */
export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html
      lang="fa"
      dir="rtl"
      suppressHydrationWarning
      className={ibmPlexSansArabic.variable}
    >
      <body className={`${ibmPlexSansArabic.className} font-ibm-plex-arabic antialiased`}>
        {children}
      </body>
    </html>
  );
}
