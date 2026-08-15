export type AppLocale = "ar" | "fa";

type BranchLike = {
  country?: string | null;
  city?: string | null;
  name?: string | null;
} | null | undefined;

type UserLike = {
  branch?: BranchLike;
} | null | undefined;

/** Iraq POS / Najaf accounts should default to Arabic + dinar. */
export function isIraqAccount(user: UserLike): boolean {
  const branch = user?.branch;
  if (!branch) return false;

  const country = branch.country || "";
  const city = branch.city || "";
  const name = branch.name || "";

  return (
    country.includes("عراق") ||
    /iraq/i.test(country) ||
    city.includes("نجف") ||
    /najaf/i.test(city) ||
    name.includes("عراق") ||
    name.includes("نجف")
  );
}

export function defaultLocaleForUser(user: UserLike): AppLocale {
  return isIraqAccount(user) ? "ar" : "fa";
}

export function persistLocalePreference(locale: AppLocale): void {
  if (typeof window === "undefined") return;
  localStorage.setItem("al-manahel-language", locale);
  localStorage.setItem("al-manahel-currency", locale === "ar" ? "IQD" : "TOMAN");
  document.documentElement.lang = locale;
}
