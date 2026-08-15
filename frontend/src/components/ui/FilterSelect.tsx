"use client";

import React, { useState, useRef, useEffect } from "react";
import { ChevronDown, Check } from "lucide-react";
import { motion, AnimatePresence } from "framer-motion";
import { cn } from "@/lib/utils";

export interface FilterSelectOption {
    value: string;
    label: string;
}

interface FilterSelectProps {
    value: string;
    onChange: (value: string) => void;
    options: FilterSelectOption[];
    placeholder?: string;
    icon?: React.ReactNode;
    className?: string;
    defaultValue?: string;
}

export function FilterSelect({
    value,
    onChange,
    options,
    placeholder,
    icon,
    className,
    defaultValue = "all",
}: FilterSelectProps) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const isActive = value !== defaultValue;
    const selected = options.find((o) => o.value === value);

    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        const handleEscape = (e: KeyboardEvent) => {
            if (e.key === "Escape") setOpen(false);
        };
        if (open) {
            document.addEventListener("mousedown", handleClickOutside);
            document.addEventListener("keydown", handleEscape);
        }
        return () => {
            document.removeEventListener("mousedown", handleClickOutside);
            document.removeEventListener("keydown", handleEscape);
        };
    }, [open]);

    return (
        <div ref={ref} className={cn("relative min-w-[148px]", open && "z-50", className)}>
            <button
                type="button"
                aria-haspopup="listbox"
                aria-expanded={open}
                onClick={() => setOpen((v) => !v)}
                className={cn(
                    "group w-full h-11 px-3 rounded-xl text-[12px] font-vazirmatn font-bold border outline-none transition-all cursor-pointer flex items-center gap-2 shadow-sm",
                    "bg-white/50 border-ink/5 hover:bg-white/80 hover:border-primary/20 focus:border-primary/30 focus:bg-white focus:ring-2 focus:ring-primary/10",
                    isActive && "border-primary/25 bg-primary/[0.04] ring-1 ring-primary/10",
                    open && "border-primary/30 bg-white ring-2 ring-primary/10"
                )}
            >
                {icon && (
                    <span
                        className={cn(
                            "shrink-0 opacity-40 transition-colors",
                            (isActive || open) && "text-primary opacity-80"
                        )}
                    >
                        {icon}
                    </span>
                )}
                <span
                    className={cn(
                        "flex-1 text-end truncate leading-none",
                        isActive ? "text-ink font-black" : "text-ink/55 font-bold"
                    )}
                >
                    {selected?.label || placeholder}
                </span>
                {isActive && (
                    <span className="w-1.5 h-1.5 rounded-full bg-primary shrink-0 shadow-sm shadow-primary/40" />
                )}
                <ChevronDown
                    className={cn(
                        "w-3.5 h-3.5 text-ink/30 shrink-0 transition-transform duration-200",
                        open && "rotate-180 text-primary",
                        "group-hover:text-ink/50"
                    )}
                />
            </button>

            <AnimatePresence>
                {open && (
                    <motion.div
                        role="listbox"
                        initial={{ opacity: 0, y: -6, scale: 0.98 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: -6, scale: 0.98 }}
                        transition={{ duration: 0.15, ease: "easeOut" }}
                        className="absolute top-full mt-1.5 inset-x-0 z-[100] bg-white/95 backdrop-blur-xl border border-white/80 rounded-xl shadow-xl shadow-ink/10 overflow-hidden py-1"
                    >
                        {options.map((option) => {
                            const isSelected = value === option.value;
                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    role="option"
                                    aria-selected={isSelected}
                                    onClick={() => {
                                        onChange(option.value);
                                        setOpen(false);
                                    }}
                                    className={cn(
                                        "w-full px-3 py-2.5 text-end text-[12px] font-vazirmatn flex items-center justify-between gap-2 transition-colors",
                                        isSelected
                                            ? "text-primary font-black bg-primary/[0.06]"
                                            : "text-ink/70 hover:bg-parchment/50 hover:text-ink"
                                    )}
                                >
                                    <Check
                                        className={cn(
                                            "w-3.5 h-3.5 shrink-0 transition-opacity",
                                            isSelected ? "opacity-100" : "opacity-0"
                                        )}
                                    />
                                    <span className="flex-1 truncate">{option.label}</span>
                                </button>
                            );
                        })}
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
