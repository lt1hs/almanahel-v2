"use client";

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";
import { useRouter } from "@/i18n/routing";
import {
  isIraqAccount,
  persistLocalePreference,
  type AppLocale,
} from "@/lib/userLocale";
import { PAGE_READY_TIMEOUT_MS } from "@/lib/pageReady";

export type UserRole =
  | "super_admin"
  | "admin"
  | "branch_manager"
  | "warehouse_staff"
  | "accountant";

interface User {
  id: string;
  name: string;
  role: UserRole;
  branch_id?: number | null;
  branch?: {
    id: number;
    name: string;
    city: string;
    type: string;
    country?: string;
    is_iraq_store?: boolean | null;
    is_central_warehouse?: boolean | null;
    is_intake_hub?: boolean | null;
    supports_dinar?: boolean | null;
    supports_toman?: boolean | null;
  } | null;
  iraq_only_visible_branches?: number[];
}

interface AuthContextType {
  user: User | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  isLoading: boolean;
  sessionExpired: boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

const INVALID_CREDENTIALS: Record<AppLocale, string> = {
  fa: "ایمیل یا رمز عبور اشتباه است",
  ar: "البريد الإلكتروني أو كلمة المرور غير صحيحة",
};

function getLocaleFromPath(): AppLocale {
  if (typeof window === "undefined") return "fa";
  return window.location.pathname.startsWith("/ar") ? "ar" : "fa";
}

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [sessionExpired, setSessionExpired] = useState(false);
  const router = useRouter();

  useEffect(() => {
    const token = localStorage.getItem("al-manahel-token");
    if (!token) {
      setIsLoading(false);
      return;
    }

    let cancelled = false;
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), PAGE_READY_TIMEOUT_MS);

    fetch(`${API_BASE}/user`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
      },
      signal: controller.signal,
    })
      .then((res) => {
        if (!res.ok) {
          const error = new Error("auth") as Error & { status: number };
          error.status = res.status;
          throw error;
        }
        return res.json();
      })
      .then((data) => {
        if (!cancelled) setUser(data);
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        const name = err && typeof err === "object" && "name" in err ? String(err.name) : "";
        const status =
          err && typeof err === "object" && "status" in err
            ? Number((err as { status: number }).status)
            : undefined;
        if (name === "AbortError" || status === undefined || Number.isNaN(status)) {
          return;
        }
        localStorage.removeItem("al-manahel-token");
        setUser(null);
        setSessionExpired(true);
      })
      .finally(() => {
        window.clearTimeout(timeoutId);
        if (!cancelled) setIsLoading(false);
      });

    return () => {
      cancelled = true;
      controller.abort();
      window.clearTimeout(timeoutId);
    };
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      const res = await fetch(`${API_BASE}/login`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ email, password }),
      });

      if (!res.ok) {
        const err = await res.json().catch(() => ({}));
        const msg =
          err?.errors?.email?.[0] ??
          err?.message ??
          INVALID_CREDENTIALS[getLocaleFromPath()];
        throw new Error(msg);
      }

      const data = await res.json();
      localStorage.setItem("al-manahel-token", data.token);
      setSessionExpired(false);
      setUser(data.user);

      const locale: AppLocale = isIraqAccount(data.user)
        ? "ar"
        : getLocaleFromPath();
      persistLocalePreference(locale);
      router.push("/dashboard", { locale });
    },
    [router]
  );

  const logout = useCallback(async () => {
    const token = localStorage.getItem("al-manahel-token");
    if (token) {
      fetch(`${API_BASE}/logout`, {
        method: "POST",
        headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
      }).catch(() => {});
    }
    localStorage.removeItem("al-manahel-token");
    const locale = getLocaleFromPath();
    setSessionExpired(false);
    setUser(null);
    router.push("/login", { locale });
  }, [router]);

  const value = useMemo(
    () => ({ user, login, logout, isLoading, sessionExpired }),
    [user, login, logout, isLoading, sessionExpired]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
}
