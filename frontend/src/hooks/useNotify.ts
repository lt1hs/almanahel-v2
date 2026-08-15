import { useCallback, useMemo } from "react";
import { useTranslation } from "@/hooks/useTranslation";
import { notify as baseNotify } from "@/lib/toast";

export function useNotify() {
  const { t } = useTranslation();

  const success = useCallback(
    (key: string, params?: Record<string, string | number>) =>
      baseNotify.success(t(key, params)),
    [t]
  );

  const error = useCallback(
    (key: string, params?: Record<string, string | number>) =>
      baseNotify.error(t(key, params)),
    [t]
  );

  const info = useCallback(
    (key: string, params?: Record<string, string | number>) =>
      baseNotify.info(t(key, params)),
    [t]
  );

  const rawSuccess = useCallback((message: string) => baseNotify.success(message), []);
  const rawError = useCallback((message: string) => baseNotify.error(message), []);
  const rawInfo = useCallback((message: string) => baseNotify.info(message), []);

  return useMemo(
    () => ({
      success,
      error,
      info,
      rawSuccess,
      rawError,
      rawInfo,
      promise: baseNotify.promise,
    }),
    [success, error, info, rawSuccess, rawError, rawInfo]
  );
}
