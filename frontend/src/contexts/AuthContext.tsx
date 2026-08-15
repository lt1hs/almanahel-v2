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
  } | null;
  iraq_only_visible_branches?: number[];
}

interface AuthContextType {
  user: User | null;
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  isLoading: boolean;
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
  const router = useRouter();

  useEffect(() => {
    const token = localStorage.getItem("al-manahel-token");
    if (!token) {
      setIsLoading(false);
      return;
    }

    let cancelled = false;
    fetch(`${API_BASE}/user`, {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: "application/json",
      },
    })
      .then((res) => (res.ok ? res.json() : Promise.reject()))
      .then((data) => {
        if (!cancelled) setUser(data);
      })
      .catch(() => {
        localStorage.removeItem("al-manahel-token");
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const login = useCallback(
    async (email: string, password: string) => {
      setIsLoading(true);
      try {
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
        setUser(data.user);

        const locale: AppLocale = isIraqAccount(data.user)
          ? "ar"
          : getLocaleFromPath();
        persistLocalePreference(locale);
        router.push("/dashboard", { locale });
      } finally {
        setIsLoading(false);
      }
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
    setUser(null);
    router.push("/login", { locale });
  }, [router]);

  const value = useMemo(
    () => ({ user, login, logout, isLoading }),
    [user, login, logout, isLoading]
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
