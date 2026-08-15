import * as React from "react";
import { cn } from "@/lib/utils";

interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
    variant?: "parchment" | "ink" | "elevated";
}

const Card = React.forwardRef<HTMLDivElement, CardProps>(
    ({ className, variant = "parchment", ...props }, ref) => {
        const variants = {
            parchment: "bg-parchment border border-ink/5",
            ink: "bg-ink text-parchment border border-ink",
            elevated: "bg-parchment shadow-lg border border-ink/10",
        };

        return (
            <div
                ref={ref}
                className={cn(
                    "rounded-[7px] transition-all duration-300",
                    variants[variant],
                    className
                )}
                {...props}
            />
        );
    }
);

Card.displayName = "Card";

const CardHeader = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn("p-6 pb-3", className)} {...props} />
);

const CardTitle = ({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) => (
    <h3 className={cn("text-xl font-bold font-vazirmatn text-ink", className)} {...props} />
);

const CardDescription = ({ className, ...props }: React.HTMLAttributes<HTMLParagraphElement>) => (
    <p className={cn("text-sm text-ink/60 font-rubik mt-1", className)} {...props} />
);

const CardContent = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn("p-6 pt-0 font-rubik", className)} {...props} />
);

const CardFooter = ({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div className={cn("p-6 pt-0 border-t border-ink/5 mt-4 flex items-center justify-between", className)} {...props} />
);

export { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter };
