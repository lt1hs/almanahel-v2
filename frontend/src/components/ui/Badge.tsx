import * as React from "react";
import { cn } from "@/lib/utils";

interface BadgeProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: "primary" | "accent" | "ink" | "outline" | "crimson" | "success";
}

const Badge = ({ className, variant = "primary", ...props }: BadgeProps) => {
    const variants = {
        primary: "bg-primary/10 text-primary border-primary/20",
        accent: "bg-accent/10 text-accent border-accent/20",
        ink: "bg-ink/10 text-ink border-ink/20",
        outline: "bg-transparent border-ink/10 text-ink",
        crimson: "bg-crimson/10 text-crimson border-crimson/20",
        success: "bg-green-600/10 text-green-700 border-green-600/20",
    };

    return (
        <div
            className={cn(
                "inline-flex items-center rounded-[4px] border px-2 py-0.5 text-[9px] font-black uppercase tracking-widest transition-colors font-vazirmatn",
                variants[variant],
                className
            )}
            {...props}
        />
    );
};

export { Badge };
