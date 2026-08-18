import type { Metadata } from "next";
import localFont from "next/font/local";
import { AuthProvider } from "@/contexts/AuthContext";
import { Toaster } from "react-hot-toast";
import ClientLayout from "@/components/ClientLayout";
import { AppProviders } from "@/components/AppProviders";
import { NextIntlClientProvider } from "next-intl";
import { getMessages, getTranslations } from "next-intl/server";
import { setRequestLocale } from "next-intl/server";
import "../globals.css";

/** Self-hosted IBM Plex Sans Arabic — offline-safe (no Google Fonts at build time). */
const ibmPlexSansArabic = localFont({
  src: [
    {
      path: "../../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-400-normal.woff2",
      weight: "400",
      style: "normal",
    },
    {
      path: "../../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-500-normal.woff2",
      weight: "500",
      style: "normal",
    },
    {
      path: "../../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-600-normal.woff2",
      weight: "600",
      style: "normal",
    },
    {
      path: "../../fonts/ibm-plex-sans-arabic/ibm-plex-sans-arabic-arabic-700-normal.woff2",
      weight: "700",
      style: "normal",
    },
  ],
  variable: "--font-ibm-plex-arabic-face",
  display: "swap",
  fallback: ["Segoe UI", "Tahoma", "system-ui", "sans-serif"],
});

export function generateStaticParams() {
  return [{ locale: "fa" }, { locale: "ar" }];
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "meta" });
  return {
    title: t("title"),
    description: t("description"),
  };
}

export default async function RootLayout({
  children,
  params,
}: Readonly<{
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}>) {
  const { locale } = await params;
  setRequestLocale(locale);
  const messages = await getMessages();

  return (
    <div
      className={`${ibmPlexSansArabic.variable} ${ibmPlexSansArabic.className} font-ibm-plex-arabic antialiased scroll-smooth`}
    >
      <NextIntlClientProvider messages={messages} locale={locale}>
        <AppProviders>
          <ClientLayout locale={locale}>
            <AuthProvider>
              <main className="relative min-h-screen">{children}</main>
              <Toaster
                position="top-center"
                toastOptions={{
                  className: "font-ibm-plex-arabic",
                  duration: 4000,
                }}
              />
            </AuthProvider>
          </ClientLayout>
        </AppProviders>
      </NextIntlClientProvider>
    </div>
  );
}
