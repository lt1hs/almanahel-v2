"use client";

import * as React from "react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/hooks/useTranslation";

interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: "primary" | "accent" | "ghost" | "outline" | "danger";
  size?: "sm" | "md" | "lg" | "icon";
  isLoading?: boolean;
}

const variants = {
  primary: "bg-primary text-white hover:bg-primary/90",
  accent: "bg-accent text-ink hover:bg-accent/90",
  ghost: "bg-transparent text-ink hover:bg-ink/5",
  outline: "bg-transparent border-2 border-primary text-primary hover:bg-primary/5",
  danger: "bg-crimson text-white hover:bg-crimson/90",
};

const sizes = {
  sm: "h-9 px-3 text-sm",
  md: "h-11 px-6 text-base",
  lg: "h-14 px-8 text-lg font-bold",
  icon: "h-10 w-10 p-2",
};

const Button = React.forwardRef<HTMLButtonElement, ButtonProps>(
  (
    { className, variant = "primary", size = "md", isLoading, children, ...props },
    ref
  ) => {
    const { t } = useTranslation();

    return (
      <button
        ref={ref}
        className={cn(
          "relative inline-flex items-center justify-center rounded-[7px] font-vazirmatn transition-colors active:scale-[0.98] disabled:opacity-50 disabled:pointer-events-none shadow-sm",
          variants[variant],
          sizes[size],
          isLoading && "cursor-wait",
          className
        )}
        {...props}
      >
        {isLoading ? (
          <span className="flex items-center gap-2">
            <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
              <circle
                className="opacity-25"
                cx="12"
                cy="12"
                r="10"
                stroke="currentColor"
                strokeWidth="4"
                fill="none"
              />
              <path
                className="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
              />
            </svg>
            {t("common.pleaseWait")}
          </span>
        ) : (
          children
        )}
      </button>
    );
  }
);

Button.displayName = "Button";

export { Button };
