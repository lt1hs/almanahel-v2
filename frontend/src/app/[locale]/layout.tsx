import type { Metadata } from "next";
import { IBM_Plex_Sans_Arabic } from "next/font/google";
import { AuthProvider } from "@/contexts/AuthContext";
import { Toaster } from "react-hot-toast";
import ClientLayout from "@/components/ClientLayout";
import { AppProviders } from "@/components/AppProviders";
import { NextIntlClientProvider } from "next-intl";
import { getMessages, getTranslations } from "next-intl/server";
import { setRequestLocale } from "next-intl/server";
import "../globals.css";

export function generateStaticParams() {
  return [{ locale: 'fa' }, { locale: 'ar' }];
}

const ibmPlexSansArabic = IBM_Plex_Sans_Arabic({
  subsets: ["arabic", "latin"],
  variable: "--font-ibm-plex-arabic",
  weight: ["400", "500", "600", "700"],
  display: "swap",
});

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
  params
}: Readonly<{
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}>) {
  const { locale } = await params;
  // Enable static rendering
  setRequestLocale(locale);
  const messages = await getMessages();

  return (
    <div
      className={`${ibmPlexSansArabic.variable} font-ibm-plex-arabic antialiased scroll-smooth`}
    >
      <NextIntlClientProvider messages={messages} locale={locale}>
        <AppProviders>
          <ClientLayout locale={locale}>
            <AuthProvider>
              <main className="relative min-h-screen">
                {children}
              </main>
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
