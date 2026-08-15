"use client";

import React from "react";
import { motion } from "framer-motion";
import { Check } from "lucide-react";
import { cn } from "@/lib/utils";

interface StepperProps {
    steps: string[];
    currentStep: number;
    className?: string;
}

export function Stepper({ steps, currentStep, className }: StepperProps) {
    return (
        <div className={cn("w-full py-2", className)}>
            <div className="flex items-center justify-between">
                {steps.map((step, index) => {
                    const isCompleted = index < currentStep;
                    const isActive = index === currentStep;

                    return (
                        <React.Fragment key={step}>
                            <div className="flex flex-col items-center relative z-10">
                                <motion.div
                                    initial={false}
                                    animate={{
                                        backgroundColor: isCompleted ? "var(--primary)" : isActive ? "var(--primary)" : "rgba(255, 255, 255, 0.4)",
                                        borderColor: isCompleted || isActive ? "rgba(32, 171, 176, 0.2)" : "rgba(13, 13, 13, 0.05)",
                                        color: isCompleted || isActive ? "#fff" : "rgba(13, 13, 13, 0.3)",
                                        boxShadow: isActive ? "0 0 15px rgba(32, 171, 176, 0.2)" : "0 0 0px rgba(0,0,0,0)",
                                    }}
                                    className={cn(
                                        "w-7 h-7 rounded-lg border flex items-center justify-center font-vazirmatn text-[11px] font-black transition-all",
                                        isActive && "scale-110",
                                        !isActive && !isCompleted && "backdrop-blur-sm"
                                    )}
                                >
                                    {isCompleted ? <Check className="w-3.5 h-3.5" /> : index + 1}
                                </motion.div>
                                <span
                                    className={cn(
                                        "absolute -bottom-5 whitespace-nowrap text-[9px] font-bold uppercase tracking-widest transition-all",
                                        isActive ? "text-primary opacity-100" : "text-ink/20 opacity-60"
                                    )}
                                >
                                    {step}
                                </span>
                            </div>

                            {index < steps.length - 1 && (
                                <div className="flex-1 h-[1px] mx-3 bg-ink/5 relative -top-2.5">
                                    <motion.div
                                        initial={{ width: "0%" }}
                                        animate={{ width: isCompleted ? "100%" : "0%" }}
                                        className="h-full bg-primary/40 shadow-[0_0_8px_rgba(32,171,176,0.3)]"
                                    />
                                </div>
                            )}
                        </React.Fragment>
                    );
                })}
            </div>
            <div className="h-6" /> {/* Spacer for labels */}
        </div>
    );
}
