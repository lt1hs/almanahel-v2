"use client";

import React, { useEffect, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { X } from "lucide-react";
import { createPortal } from "react-dom";
import { useTranslation } from "@/hooks/useTranslation";
import { cn } from "@/lib/utils";

interface ModalProps {
    isOpen: boolean;
    onClose: () => void;
    title?: string;
    description?: string;
    children: React.ReactNode;
    size?: "md" | "lg";
    className?: string;
}

export function Modal({
    isOpen,
    onClose,
    title,
    description,
    children,
    size = "md",
    className,
}: ModalProps) {
    const { t, language } = useTranslation();
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        setMounted(true);

        const handleEscape = (e: KeyboardEvent) => {
            if (e.key === "Escape") onClose();
        };

        if (isOpen) {
            document.body.style.overflow = "hidden";
            window.addEventListener("keydown", handleEscape);
        } else {
            document.body.style.overflow = "unset";
        }

        return () => {
            document.body.style.overflow = "unset";
            window.removeEventListener("keydown", handleEscape);
        };
    }, [isOpen, onClose]);

    if (!mounted) return null;

    return createPortal(
        <AnimatePresence>
            {isOpen && (
                <div className="fixed inset-0 z-[9999]">
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.2 }}
                        onClick={onClose}
                        className="fixed inset-0 z-[9999] bg-ink/45 backdrop-blur-[6px]"
                    />

                    <div className="fixed inset-0 z-[10000] flex items-center justify-center overflow-y-auto p-4 sm:p-6 pointer-events-none">
                        <motion.div
                            initial={{ opacity: 0, y: 16, scale: 0.98 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 10, scale: 0.98 }}
                            transition={{ type: "spring", duration: 0.45, bounce: 0.18 }}
                            dir={language === "ar" || language === "fa" ? "rtl" : "ltr"}
                            className={cn(
                                "pointer-events-auto relative w-full overflow-hidden rounded-3xl border border-white/70 bg-white/95 font-ibm-plex-arabic shadow-[0_24px_80px_rgba(13,13,13,0.18)] backdrop-blur-2xl",
                                size === "lg" ? "max-w-2xl" : "max-w-lg",
                                className
                            )}
                        >
                            <div className="pointer-events-none absolute -top-24 -end-16 h-56 w-56 rounded-full bg-primary/10 blur-3xl" />
                            <div className="pointer-events-none absolute -bottom-28 -start-20 h-52 w-52 rounded-full bg-accent/10 blur-3xl" />

                            {(title || description) && (
                                <div className="relative flex items-start justify-between gap-4 border-b border-ink/5 bg-parchment/30 px-5 py-4 sm:px-6">
                                    <div className="min-w-0 pt-0.5">
                                        {title && (
                                            <h3 className="truncate text-[17px] font-bold font-ibm-plex-arabic tracking-tight text-ink">
                                                {title}
                                            </h3>
                                        )}
                                        {description && (
                                            <p className="mt-1 text-[11px] font-medium font-ibm-plex-arabic leading-5 text-ink/40">
                                                {description}
                                            </p>
                                        )}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={onClose}
                                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-ink/5 bg-white/80 text-ink/35 transition-all hover:border-primary/20 hover:bg-white hover:text-primary active:scale-95"
                                        title={t("common.close")}
                                        aria-label={t("common.close")}
                                    >
                                        <X className="h-4 w-4" />
                                    </button>
                                </div>
                            )}

                            <div className="relative max-h-[min(70vh,640px)] overflow-y-auto px-5 py-5 sm:px-6 sm:py-6">
                                {children}
                            </div>
                        </motion.div>
                    </div>
                </div>
            )}
        </AnimatePresence>,
        document.body
    );
}
