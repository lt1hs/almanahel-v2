"use client";

import React, { useEffect, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import { X } from "lucide-react";
import { cn } from "@/lib/utils";
import { createPortal } from "react-dom";
import { useTranslation } from "@/hooks/useTranslation";

interface ModalProps {
    isOpen: boolean;
    onClose: () => void;
    title?: string;
    children: React.ReactNode;
}

export function Modal({ isOpen, onClose, title, children }: ModalProps) {
    const { t } = useTranslation();
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
                <div className="fixed inset-0 z-[9999] overflow-hidden">
                    {/* Backdrop */}
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={onClose}
                        className="fixed inset-0 bg-ink/70 backdrop-blur-[12px] z-[9999]"
                    />
                    
                    {/* Modal Content Wrapper */}
                    <div className="fixed inset-0 z-[10000] flex items-center justify-center p-4 sm:p-6 overflow-y-auto pointer-events-none">
                        <motion.div
                            initial={{ opacity: 0, scale: 0.9, y: 30 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.9, y: 30 }}
                            transition={{ 
                                type: "spring", 
                                duration: 0.6, 
                                bounce: 0.3,
                                damping: 25,
                                stiffness: 300
                            }}
                            className="bg-white/90 backdrop-blur-3xl border border-white/50 w-full max-w-lg rounded-[28px] shadow-[0_40px_100px_rgba(0,0,0,0.5),0_0_0_1px_rgba(255,255,255,0.5)_inset] overflow-hidden pointer-events-auto relative"
                            dir="rtl"
                        >
                            {/* Decorative Background Glow */}
                            <div className="absolute top-0 right-0 w-64 h-64 bg-primary/5 rounded-full blur-[80px] -translate-y-1/2 translate-x-1/2 -z-10 pointer-events-none" />

                            {/* Header */}
                            <div className="px-8 py-6 border-b border-ink/[0.03] flex items-center justify-between bg-white/40">
                                {title && (
                                    <div className="flex flex-col gap-0.5">
                                        <h3 className="text-2xl font-black font-vazirmatn text-ink tracking-tight">{title}</h3>
                                        <div className="w-12 h-1 bg-primary/20 rounded-full" />
                                    </div>
                                )}
                                <button
                                    onClick={onClose}
                                    className="w-12 h-12 flex items-center justify-center bg-white/50 border border-white hover:bg-white hover:border-primary/20 rounded-2xl transition-all shadow-sm text-ink/30 hover:text-primary active:scale-90 group"
                                    title={t("common.close")}
                                >
                                    <X className="w-6 h-6 group-hover:rotate-90 transition-transform duration-300" />
                                </button>
                            </div>

                            {/* Body */}
                            <div className="p-10">
                                <motion.div
                                    initial={{ opacity: 0, y: 10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: 0.1, duration: 0.4 }}
                                >
                                    {children}
                                </motion.div>
                            </div>
                        </motion.div>
                    </div>
                </div>
            )}
        </AnimatePresence>,
        document.body
    );
}
