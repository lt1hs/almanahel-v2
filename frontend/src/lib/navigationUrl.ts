export type AppLocation = {
  pathname: string;
  search: string;
};

function originOf(base: string): string | null {
  try {
    return new URL(base).origin;
  } catch {
    return null;
  }
}

export function parseAppLocation(href: string, base = "http://local.test"): AppLocation {
  const url = new URL(href, base);
  let pathname = url.pathname.replace(/^\/(fa|ar)(?=\/|$)/, "") || "/";
  if (pathname.length > 1) pathname = pathname.replace(/\/+$/, "");
  return { pathname, search: url.search };
}

export function isInternalAppHref(href: string | null, currentHref?: string): boolean {
  if (!href) return false;
  if (href.startsWith("#") || href.startsWith("mailto:") || href.startsWith("tel:")) return false;
  if (href.startsWith("http://") || href.startsWith("https://")) {
    try {
      const url = new URL(href);
      const origin = currentHref ? originOf(currentHref) : null;
      if (origin && url.origin !== origin) return false;
      if (!origin && typeof window !== "undefined" && url.origin !== window.location.origin) {
        return false;
      }
      href = url.pathname + url.search;
    } catch {
      return false;
    }
  }
  return href.startsWith("/") && !href.startsWith("//");
}

export function sameDestination(href: string, currentHref: string): boolean {
  try {
    const next = parseAppLocation(href, currentHref);
    const current = parseAppLocation(currentHref);
    return next.pathname === current.pathname && next.search === current.search;
  } catch {
    return false;
  }
}

export function shouldStartNavigation(href: string, currentHref: string): boolean {
  if (!isInternalAppHref(href, currentHref)) return false;
  try {
    return parseAppLocation(href, currentHref).pathname !== parseAppLocation(currentHref).pathname;
  } catch {
    return false;
  }
}
