"use client";

import React, { useState, useEffect } from "react";
import Image from "next/image";
import { useRouter } from "@/i18n/routing";
import { motion } from "framer-motion";
import { Lock, Mail } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from "@/components/ui/Card";
import { Input } from "@/components/ui/Input";
import { useAuth } from "@/contexts/AuthContext";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { isIraqAccount } from "@/lib/userLocale";

export default function LoginPage() {
    const { login, user, isLoading: isAuthLoading } = useAuth();
    const { t } = useTranslation();
    const notify = useNotify();
    const router = useRouter();
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [isLoading, setIsLoading] = useState(false);

    useEffect(() => {
        if (!isAuthLoading && user) {
            router.push("/dashboard", {
                locale: isIraqAccount(user) ? "ar" : undefined,
            });
        }
    }, [user, isAuthLoading, router]);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        setIsLoading(true);
        try {
            await login(email, password);
            notify.success("auth.welcomeMessage");
        } catch (err: any) {
            if (err?.message) notify.rawError(err.message);
            else notify.error("auth.loginError");
        } finally {
            setIsLoading(false);
        }
    };

    return (
        <div className="flex min-h-screen items-center justify-center p-4 bg-parchment relative overflow-hidden">
            <div className="absolute top-0 left-0 w-full h-full overflow-hidden pointer-events-none">
                <motion.div
                    animate={{
                        scale: [1, 1.2, 1],
                        rotate: [0, 90, 0],
                        x: [0, 100, 0],
                        y: [0, 50, 0]
                    }}
                    transition={{ duration: 20, repeat: Infinity, ease: "linear" }}
                    className="absolute -top-24 -right-24 w-[500px] h-[500px] bg-primary/10 rounded-full blur-[100px]"
                />
                <motion.div
                    animate={{
                        scale: [1.2, 1, 1.2],
                        rotate: [0, -90, 0],
                        x: [0, -100, 0],
                        y: [0, -50, 0]
                    }}
                    transition={{ duration: 25, repeat: Infinity, ease: "linear" }}
                    className="absolute -bottom-24 -left-24 w-[600px] h-[600px] bg-accent/10 rounded-full blur-[120px]"
                />
                <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-full h-full bg-[radial-gradient(circle_at_center,transparent_0%,rgba(245,245,245,0.8)_100%)]" />
            </div>

            <motion.div
                initial={{ opacity: 0, scale: 0.95, y: 20 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                transition={{ duration: 0.8, ease: "easeOut" }}
                className="w-full max-w-[380px] z-10"
            >
                <div className="relative group">
                    <div className="absolute -inset-1 bg-gradient-to-r from-primary/10 to-accent/10 rounded-[24px] blur-sm opacity-20 group-hover:opacity-30 transition duration-1000"></div>

                    <Card className="relative shadow-xl border border-white/50 bg-white/80 backdrop-blur-2xl rounded-[18px] overflow-hidden">
                        <CardHeader className="text-center pt-8 pb-4">
                            <motion.div
                                initial={{ scale: 0.5, opacity: 0 }}
                                animate={{ scale: 1, opacity: 1 }}
                                transition={{ delay: 0.3, type: "spring", stiffness: 200 }}
                                className="mx-auto w-16 h-16 bg-gradient-to-br from-white to-primary/5 rounded-xl flex items-center justify-center mb-4 border border-primary/10 shadow-inner group-hover:border-primary/20 transition-colors duration-500 p-2.5"
                            >
                                <Image
                                    src="/logo-3.svg"
                                    alt={t("common.appName")}
                                    width={40}
                                    height={40}
                                    className="w-10 h-10 object-contain"
                                    priority
                                />
                            </motion.div>
                            <CardTitle className="text-2xl font-vazirmatn text-ink font-bold tracking-tight">
                                {t("common.appName")}
                            </CardTitle>
                            <CardDescription className="text-ink/50 mt-1.5 text-sm font-medium">
                                {t("auth.loginSubtitle")}
                            </CardDescription>
                        </CardHeader>

                        <CardContent className="px-6 pb-8">
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <motion.div
                                    initial={{ opacity: 0, x: -10 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.5 }}
                                >
                                    <Input
                                        label={t("auth.email")}
                                        type="email"
                                        placeholder={t("auth.emailPlaceholder")}
                                        value={email}
                                        onChange={(e) => setEmail(e.target.value)}
                                        icon={<Mail className="w-4 h-4 text-primary/60" />}
                                        required
                                        className="h-10 text-sm bg-white/50 border-ink/5 focus:border-primary/40 transition-all duration-300"
                                    />
                                </motion.div>

                                <motion.div
                                    initial={{ opacity: 0, x: -10 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ delay: 0.6 }}
                                >
                                    <Input
                                        label={t("auth.password")}
                                        type="password"
                                        placeholder={t("auth.passwordPlaceholder")}
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        icon={<Lock className="w-4 h-4 text-primary/60" />}
                                        required
                                        className="h-10 text-sm bg-white/50 border-ink/5 focus:border-primary/40 transition-all duration-300"
                                    />
                                </motion.div>

                                <motion.div
                                    initial={{ opacity: 0, y: 10 }}
                                    animate={{ opacity: 1, y: 0 }}
                                    transition={{ delay: 0.7 }}
                                    className="pt-2"
                                >
                                    <Button
                                        type="submit"
                                        className="w-full h-10 text-base font-semibold shadow-md shadow-primary/10 hover:shadow-primary/20 active:scale-[0.98] transition-all duration-300"
                                        isLoading={isLoading}
                                        size="md"
                                    >
                                        {t("auth.loginButton")}
                                    </Button>
                                </motion.div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </motion.div>
        </div>
    );
}
