"use client";

import React, {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";
import { cn } from "@/lib/utils";
import { PAGE_READY_TIMEOUT_MS } from "@/lib/pageReady";
import { shouldStartNavigation } from "@/lib/navigationUrl";
import { PageLoader } from "@/components/ui/Loading";

type NavigationLoadingContextValue = {
  isNavigating: boolean;
  start: () => void;
  stop: () => void;
};

const NavigationLoadingContext = createContext<NavigationLoadingContextValue | null>(null);

type PageReadyContextValue = {
  markReady: () => void;
  register: () => () => void;
};

const PageReadyContext = createContext<PageReadyContextValue | null>(null);

function isModifiedClick(event: MouseEvent): boolean {
  return event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
}

function hrefFromHistoryUrl(url: unknown): string | null {
  if (typeof url === "string") return url;
  if (url instanceof URL) return url.href;
  return null;
}

export function NavigationProgressProvider({ children }: { children: React.ReactNode }) {
  const [isNavigating, setIsNavigating] = useState(false);
  const safetyTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const stopTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const startedAt = useRef(0);
  const navigatingRef = useRef(false);

  const clearTimers = useCallback(() => {
    if (safetyTimer.current) {
      clearTimeout(safetyTimer.current);
      safetyTimer.current = null;
    }
    if (stopTimer.current) {
      clearTimeout(stopTimer.current);
      stopTimer.current = null;
    }
  }, []);

  const stop = useCallback(() => {
    const elapsed = Date.now() - startedAt.current;
    const wait = Math.max(0, 200 - elapsed);
    if (stopTimer.current) clearTimeout(stopTimer.current);
    stopTimer.current = setTimeout(() => {
      navigatingRef.current = false;
      if (safetyTimer.current) {
        clearTimeout(safetyTimer.current);
        safetyTimer.current = null;
      }
      setIsNavigating(false);
      stopTimer.current = null;
    }, wait);
  }, []);

  const start = useCallback(() => {
    if (stopTimer.current) {
      clearTimeout(stopTimer.current);
      stopTimer.current = null;
    }
    navigatingRef.current = true;
    startedAt.current = Date.now();
    setIsNavigating(true);
    if (safetyTimer.current) clearTimeout(safetyTimer.current);
    safetyTimer.current = setTimeout(() => {
      navigatingRef.current = false;
      setIsNavigating(false);
      safetyTimer.current = null;
    }, PAGE_READY_TIMEOUT_MS);
  }, []);

  const startDeferred = useCallback(() => {
    window.setTimeout(() => {
      start();
    }, 0);
  }, [start]);

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (isModifiedClick(event) || event.defaultPrevented) return;
      const target = event.target as HTMLElement | null;
      const anchor = target?.closest?.("a[href]") as HTMLAnchorElement | null;
      if (!anchor) return;
      if (anchor.target && anchor.target !== "_self") return;
      if (anchor.hasAttribute("download")) return;
      const href = anchor.getAttribute("href");
      if (!href || !shouldStartNavigation(href, window.location.href)) return;
      startDeferred();
    };

    const onPopState = () => startDeferred();

    document.addEventListener("click", onClick, true);
    window.addEventListener("popstate", onPopState);
    return () => {
      document.removeEventListener("click", onClick, true);
      window.removeEventListener("popstate", onPopState);
      clearTimers();
    };
  }, [clearTimers, startDeferred]);

  useEffect(() => {
    const originalPush = history.pushState.bind(history);
    const originalReplace = history.replaceState.bind(history);

    const wrap =
      (original: typeof history.pushState) =>
      (...args: Parameters<typeof history.pushState>) => {
        try {
          const href = hrefFromHistoryUrl(args[2]);
          if (href && shouldStartNavigation(href, window.location.href)) {
            startDeferred();
          }
        } catch {
          // ignore malformed URLs
        }
        return original(...args);
      };

    history.pushState = wrap(originalPush);
    history.replaceState = wrap(originalReplace);

    return () => {
      history.pushState = originalPush;
      history.replaceState = originalReplace;
    };
  }, [startDeferred]);

  const value = useMemo(
    () => ({ isNavigating, start: startDeferred, stop }),
    [isNavigating, startDeferred, stop]
  );

  return (
    <NavigationLoadingContext.Provider value={value}>
      {children}
      <NavigationProgressUi active={isNavigating} />
    </NavigationLoadingContext.Provider>
  );
}

export function useNavigationLoading(): NavigationLoadingContextValue {
  const ctx = useContext(NavigationLoadingContext);
  if (!ctx) {
    return {
      isNavigating: false,
      start: () => undefined,
      stop: () => undefined,
    };
  }
  return ctx;
}

export function PageReadyGate({ children }: { children: React.ReactNode }) {
  const { stop } = useNavigationLoading();
  const [revealed, setRevealed] = useState(false);
  const revealedRef = useRef(false);
  const subscriberCount = useRef(0);

  const markReady = useCallback(() => {
    if (revealedRef.current) return;
    revealedRef.current = true;
    setRevealed(true);
    stop();
  }, [stop]);

  const register = useCallback(() => {
    subscriberCount.current += 1;
    return () => {
      subscriberCount.current = Math.max(0, subscriberCount.current - 1);
    };
  }, []);

  useEffect(() => {
    const safety = window.setTimeout(() => {
      markReady();
    }, PAGE_READY_TIMEOUT_MS);
    return () => window.clearTimeout(safety);
  }, [markReady]);

  useEffect(() => {
    const id = window.setTimeout(() => {
      if (subscriberCount.current === 0) markReady();
    }, 0);
    return () => window.clearTimeout(id);
  }, [markReady]);

  const value = useMemo(() => ({ markReady, register }), [markReady, register]);

  return (
    <PageReadyContext.Provider value={value}>
      <div className={cn("relative", !revealed && "min-h-[60vh]")}>
        {!revealed && (
          <div className="absolute inset-0 z-10 flex items-center justify-center bg-parchment">
            <PageLoader />
          </div>
        )}
        <div className={cn(!revealed && "invisible")} inert={!revealed ? true : undefined}>
          {children}
        </div>
      </div>
    </PageReadyContext.Provider>
  );
}

export function usePageReady(isReady: boolean) {
  const ctx = useContext(PageReadyContext);

  useEffect(() => {
    if (!ctx) return undefined;
    return ctx.register();
  }, [ctx]);

  useEffect(() => {
    if (!ctx || !isReady) return;
    ctx.markReady();
  }, [ctx, isReady]);
}

function NavigationProgressUi({ active }: { active: boolean }) {
  return (
    <div
      aria-hidden={!active}
      className={cn(
        "pointer-events-none fixed inset-x-0 top-0 z-[99998] h-[3px] overflow-hidden transition-opacity duration-200",
        active ? "opacity-100" : "opacity-0"
      )}
    >
      <div
        className={cn(
          "h-full origin-right bg-gradient-to-l from-primary via-primary to-accent",
          active ? "w-[85%] animate-nav-progress" : "w-full"
        )}
      />
    </div>
  );
}
