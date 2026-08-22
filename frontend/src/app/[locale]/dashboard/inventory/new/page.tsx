"use client";

import React, { useMemo, useState } from "react";
import { motion, AnimatePresence, Variants } from "framer-motion";
import { ChevronRight, ChevronLeft, Save, ArrowRight, Book as BookIcon, User, Hash, DollarSign, Package, MapPin, CheckCircle2, ShoppingBag } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Card, CardContent } from "@/components/ui/Card";
import { Stepper } from "@/components/ui/Stepper";
import { SupplierSelect, type SupplierAccountSelection } from "@/components/inventory/SupplierSelect";
import { BookForm } from "@/components/inventory/BookForm";
import { cn } from "@/lib/utils";

export const dynamic = 'force-static';
import { Badge } from "@/components/ui/Badge";
import { useRouter } from "@/i18n/routing";
import { useTranslation } from "@/hooks/useTranslation";
import { useNotify } from "@/hooks/useNotify";
import { notify } from "@/lib/toast";
import { apiRequest } from "@/lib/api";
import { addStockIntake, bookPayloadFromForm, defaultBookFormState, syncBookBranchInventories } from "@/lib/bookIntake";
import { buildPostBooksPayload } from "@/lib/bookCatalogRequests";
import { parsePriceDigits, resolveBranchId, stockKeyForBranch, type BranchStockKey } from "@/lib/bookFormUtils";
import { useAuth } from "@/contexts/AuthContext";
import { useInvalidateNotifications } from "@/hooks/useNotificationInbox";

const containerVariants: Variants = {
    hidden: { opacity: 0 },
    show: {
        opacity: 1,
        transition: { staggerChildren: 0.1, delayChildren: 0.2 }
    }
};

const itemVariants: Variants = {
    hidden: { opacity: 0, y: 20 },
    show: { opacity: 1, y: 0, transition: { type: "spring", stiffness: 300, damping: 24 } }
};

const sidebarVariants: Variants = {
    hidden: { opacity: 0, x: 50 },
    show: { opacity: 1, x: 0, transition: { type: "spring", stiffness: 200, damping: 25, delay: 0.4 } }
};

export default function NewInventoryPage() {
    const router = useRouter();
    const { t, formatNumber } = useTranslation();
    const notifyToast = useNotify();
    const { user } = useAuth();
    const invalidateNotifications = useInvalidateNotifications();
    const [currentStep, setCurrentStep] = useState(0);
    const [intakeInfo, setIntakeInfo] = useState<{
        can_intake: boolean;
        can_intake_iraq_only: boolean;
        default_intake_branch_id: number | null;
        default_iraq_branch_id: number | null;
    } | null>(null);
    const [branches, setBranches] = useState<any[]>([]);
    const [formData, setFormData] = useState({
        supplier: null as SupplierAccountSelection | null,
        book: defaultBookFormState(),
    });

    const steps = [
        t("inventory.wizard.stepSupplier"),
        t("inventory.wizard.stepBook"),
        t("inventory.wizard.stepReview"),
    ];

    React.useEffect(() => {
        Promise.all([
            apiRequest("/inventory/intake-info"),
            apiRequest("/branches"),
        ])
            .then(([info, branchList]) => {
                setIntakeInfo(info);
                setBranches(Array.isArray(branchList) ? branchList : []);
            })
            .catch(() => {
                setIntakeInfo(null);
                setBranches([]);
            });
    }, []);

    const canSubmit = Boolean(intakeInfo?.can_intake || intakeInfo?.can_intake_iraq_only);
    const isHubIntake = user?.role === "super_admin" || user?.role === "admin" || user?.role === "warehouse_staff";
    const posBranch = useMemo(() => {
        if (user?.branch_id) {
            const fromList = branches.find((b) => Number(b.id) === Number(user.branch_id));
            if (fromList) return fromList;
        }
        return user?.branch || null;
    }, [branches, user?.branch, user?.branch_id]);
    const posKey = stockKeyForBranch(posBranch);

    const visibleStockKeys = useMemo((): BranchStockKey[] => {
        if (isHubIntake) {
            return formData.book.iraqOnly ? ["warehouse", "qom", "najaf"] : ["warehouse", "qom"];
        }
        return posKey ? [posKey] : ["qom"];
    }, [formData.book.iraqOnly, isHubIntake, posKey]);

    const stockFieldLabels = useMemo(() => {
        if (isHubIntake || !posBranch?.name) return undefined;
        const key = posKey || "qom";
        return { [key]: posBranch.name } as Partial<Record<BranchStockKey, string>>;
    }, [isHubIntake, posBranch?.name, posKey]);

    const catalogBranchId = useMemo(() => {
        if (!isHubIntake) {
            return user?.branch_id ?? user?.branch?.id ?? null;
        }
        for (const key of visibleStockKeys) {
            const id = resolveBranchId(branches, key);
            if (id) return id;
        }
        return intakeInfo?.default_intake_branch_id ?? null;
    }, [branches, intakeInfo?.default_intake_branch_id, isHubIntake, user?.branch?.id, user?.branch_id, visibleStockKeys]);

    const priceScope = !isHubIntake && posKey === "najaf"
        ? "iraq"
        : !isHubIntake && posKey === "mashhad"
            ? "mashhad"
            : !isHubIntake
                ? "qom"
                : "all";

    const intakeQty = useMemo(
        () => visibleStockKeys.reduce(
            (sum, key) => sum + (parseInt(formData.book.branchStock?.[key] || "0", 10) || 0),
            0
        ),
        [formData.book.branchStock, visibleStockKeys]
    );

    const handleNext = () => {
        if (currentStep === 0 && !formData.supplier) {
            notifyToast.error("toast.selectSupplierFirst");
            return;
        }
        if (currentStep === 1 && (!formData.book.title || intakeQty <= 0)) {
            notifyToast.error("toast.titleQtyRequired");
            return;
        }
        setCurrentStep((prev) => Math.min(prev + 1, steps.length - 1));
    };

    const handleBack = () => {
        setCurrentStep((prev) => Math.max(prev - 1, 0));
    };

    const handleFinish = async () => {
        if (!canSubmit) {
            notifyToast.error("toast.intakeNotAllowed");
            return;
        }

        await notify.promise(
            (async () => {
                const postBranchId = !isHubIntake
                    ? Number(user?.branch_id)
                    : (() => {
                        for (const key of visibleStockKeys) {
                            const qty = parseInt(parsePriceDigits(formData.book.branchStock?.[key]), 10) || 0;
                            if (qty > 0) {
                                const id = resolveBranchId(branches, key);
                                if (id) return id;
                            }
                        }
                        return intakeInfo?.default_intake_branch_id ?? null;
                    })();

                const postBody = buildPostBooksPayload(
                    { role: user?.role, branch_id: user?.branch_id ?? user?.branch?.id },
                    bookPayloadFromForm(formData.book),
                    postBranchId
                );
                if ("error" in postBody) {
                    throw new Error("branch_required");
                }

                const created = await apiRequest("/books", {
                    method: "POST",
                    body: JSON.stringify(postBody.payload),
                });
                const bookId = created?.book?.id ?? created?.id;

                if (!isHubIntake && user?.branch_id) {
                    const selling = priceScope === "iraq"
                        ? parseFloat(parsePriceDigits(formData.book.priceDinar)) || 0
                        : priceScope === "mashhad"
                            ? parseFloat(parsePriceDigits(formData.book.priceTomanMashhad || formData.book.priceTomanQom)) || 0
                            : parseFloat(parsePriceDigits(formData.book.priceTomanQom)) || 0;
                    const cost = priceScope === "iraq"
                        ? parseFloat(parsePriceDigits(formData.book.costPriceDinar)) || 0
                        : parseFloat(parsePriceDigits(formData.book.costPriceToman)) || 0;
                    await addStockIntake({
                        bookId,
                        branchId: Number(user.branch_id),
                        quantity: intakeQty,
                        type: formData.book.type === "consignment" ? "consignment" : "owned",
                        selection: formData.supplier,
                        currency: priceScope === "iraq" ? "dinar" : "toman",
                        costPrice: cost,
                        sellingPrice: selling || cost,
                        notes: formData.book.notes || null,
                        receivedAt: formData.book.settlementDate || null,
                    });
                } else {
                    await syncBookBranchInventories(
                        formData.book,
                        branches,
                        formData.supplier,
                        bookId
                    );
                }
                invalidateNotifications();
            })(),
            {
                loading: t("toast.ledgerSaving"),
                success: t("toast.bookRegistered"),
                error: t("toast.bookRegisterError"),
            }
        );
        router.push("/dashboard/inventory");
    };

    return (
        <motion.div
            variants={containerVariants}
            initial="hidden"
            animate="show"
            className="space-y-4 pb-12 relative min-h-[70vh]"
        >
            {/* Ambient Background Elements */}
            <div className="fixed inset-0 pointer-events-none overflow-hidden -z-10">
                <motion.div
                    animate={{
                        scale: [1, 1.3, 1],
                        opacity: [0.1, 0.2, 0.1],
                        rotate: [0, 90, 0]
                    }}
                    transition={{ duration: 25, repeat: Infinity, ease: "linear" }}
                    className="absolute -top-1/4 -right-1/4 w-[800px] h-[800px] bg-primary/10 rounded-full blur-[160px]"
                />
                <motion.div
                    animate={{
                        scale: [1.2, 1, 1.2],
                        opacity: [0.05, 0.15, 0.05],
                        rotate: [0, -90, 0]
                    }}
                    transition={{ duration: 20, repeat: Infinity, ease: "linear", delay: 5 }}
                    className="absolute -bottom-1/4 -left-1/4 w-[600px] h-[600px] bg-indigo-500/10 rounded-full blur-[140px]"
                />
            </div>

            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <motion.div variants={itemVariants} className="flex items-center gap-3 min-w-0">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-10 w-10 p-0 rounded-xl border border-ink/5 bg-white/70 shrink-0"
                        onClick={() => router.push("/dashboard/inventory")}
                    >
                        <ArrowRight className="w-4 h-4" />
                    </Button>
                    <div className="min-w-0">
                        <h1 className="text-xl font-black font-vazirmatn text-ink truncate">
                            {t("inventory.addToWarehouse")}
                        </h1>
                        <p className="text-[10px] text-ink/35 font-bold mt-1">
                            {steps[currentStep]}
                        </p>
                    </div>
                </motion.div>

                <motion.div variants={itemVariants}>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.push("/dashboard/inventory")}
                        className="h-9 px-4 rounded-xl text-[11px] font-black shrink-0"
                    >
                        {t("inventory.wizard.cancel")}
                    </Button>
                </motion.div>
            </div>

            {intakeInfo && (
                <motion.div variants={itemVariants} className={cn(
                    "rounded-xl border px-4 py-2 text-[11px] font-bold font-vazirmatn",
                    canSubmit ? "bg-primary/5 border-primary/15 text-primary" : "bg-rose-50 border-rose-100 text-rose-600"
                )}>
                    {canSubmit
                        ? (isHubIntake ? t("inventory.intakeNotice") : t("inventory.posIntakeNotice"))
                        : t("toast.intakeNotAllowed")}
                </motion.div>
            )}

            {/* Main Layout Grid */}
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-10">
                {/* Form Section */}
                <div className="lg:col-span-7 space-y-8">
                    <motion.div variants={itemVariants} className="bg-white/40 backdrop-blur-xl border border-white/80 rounded-[10px] p-2 shadow-sm">
                        <Stepper steps={steps} currentStep={currentStep} className="px-6 py-4" />
                    </motion.div>

                    <motion.div variants={itemVariants}>
                        <Card className="border border-white/80 bg-white/60 backdrop-blur-3xl shadow-[0_20px_50px_rgba(0,0,0,0.04)] rounded-[10px] overflow-hidden border-t-white">
                            <CardContent className="p-8 md:p-12">
                                <AnimatePresence mode="wait">
                                    <motion.div
                                        key={currentStep}
                                        initial={{ opacity: 0, x: 20 }}
                                        animate={{ opacity: 1, x: 0 }}
                                        exit={{ opacity: 0, x: -20 }}
                                        transition={{ duration: 0.4, ease: [0.23, 1, 0.32, 1] }}
                                        className="min-h-[400px]"
                                    >
                                        {currentStep === 0 && (
                                            <div className="space-y-6">
                                                <div className="space-y-2">
                                                    <h2 className="text-xl font-black font-vazirmatn text-ink">{t("inventory.wizard.supplierTitle")}</h2>
                                                    <p className="text-sm text-ink/50 font-medium">{t("inventory.wizard.supplierDesc")}</p>
                                                </div>
                                                <SupplierSelect
                                                    branchId={user?.branch_id ?? intakeInfo?.default_intake_branch_id ?? branches[0]?.id ?? null}
                                                    onSelect={(selection) => setFormData({ ...formData, supplier: selection })}
                                                    selectedAccountId={formData.supplier?.accountId}
                                                />
                                            </div>
                                        )}

                                        {currentStep === 1 && (
                                            <div className="space-y-6">
                                                <div className="space-y-2">
                                                    <h2 className="text-xl font-black font-vazirmatn text-ink">{t("inventory.wizard.bookTitle")}</h2>
                                                    <p className="text-sm text-ink/50 font-medium">{t("inventory.wizard.bookDesc")}</p>
                                                </div>
                                                <BookForm
                                                    data={formData.book}
                                                    onChange={(book) => setFormData({ ...formData, book })}
                                                    stockFields="intake"
                                                    visibleStockKeys={visibleStockKeys}
                                                    stockFieldLabels={stockFieldLabels}
                                                    priceScope={priceScope}
                                                    catalogBranchId={catalogBranchId}
                                                />
                                            </div>
                                        )}

                                        {currentStep === 2 && (
                                            <div className="space-y-8">
                                                <div className="space-y-2">
                                                    <h2 className="text-xl font-black font-vazirmatn text-ink">{t("inventory.wizard.reviewTitle")}</h2>
                                                    <p className="text-sm text-ink/50 font-medium">{t("inventory.wizard.reviewDesc")}</p>
                                                </div>

                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                    <div className="p-6 bg-white/40 border border-white rounded-[10px] space-y-4 shadow-sm group hover:bg-white/60 transition-colors">
                                                        <div className="flex items-center gap-3 text-primary">
                                                            <div className="p-2 bg-primary/10 rounded-[5px]">
                                                                <MapPin className="w-4 h-4" />
                                                            </div>
                                                            <span className="text-[10px] font-black uppercase tracking-widest">{t("inventory.wizard.supplierInfo")}</span>
                                                        </div>
                                                        <div>
                                                            <p className="font-vazirmatn font-black text-lg text-ink">{formData.supplier?.name}</p>
                                                            <p className="text-[11px] text-ink/40 font-bold mt-1 uppercase tracking-wider">
                                                                {t("distribution.branchFallback")}: {formData.supplier?.branchId}
                                                            </p>
                                                        </div>
                                                    </div>

                                                    <div className="p-6 bg-white/40 border border-white rounded-[10px] space-y-4 shadow-sm group hover:bg-white/60 transition-colors">
                                                        <div className="flex items-center gap-3 text-accent">
                                                            <div className="p-2 bg-accent/10 rounded-[5px]">
                                                                <Package className="w-4 h-4" />
                                                            </div>
                                                            <span className="text-[10px] font-black uppercase tracking-widest">{t("inventory.wizard.shipmentDetails")}</span>
                                                        </div>
                                                        <div className="flex justify-between items-end">
                                                            <div>
                                                                <p className="font-vazirmatn font-black text-lg text-ink">{formatNumber(intakeQty)} <span className="text-xs text-ink/30 font-bold">{t("common.quantity")}</span></p>
                                                                <Badge className={cn(
                                                                    "mt-2 text-[9px] px-2 py-0.5 rounded-full border",
                                                                    formData.book.type === "consignment"
                                                                        ? "bg-amber-500/10 text-amber-600 border-amber-500/20"
                                                                        : "bg-blue-500/10 text-blue-600 border-blue-500/20"
                                                                )}>
                                                                    {formData.book.type === "consignment" ? t("inventory.consignment") : t("inventory.owned")}
                                                                </Badge>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div className="p-8 bg-gradient-to-br from-primary/[0.03] to-indigo-500/[0.03] border border-white rounded-[10px] relative overflow-hidden shadow-inner">
                                                    <div className="relative z-10 grid grid-cols-1 md:grid-cols-2 gap-8">
                                                        <div className="space-y-4">
                                                            <div className="flex items-center gap-3 text-ink/30">
                                                                <BookIcon className="w-4 h-4" />
                                                                <span className="text-[9px] font-black uppercase tracking-[0.2em]">{t("inventory.wizard.bookSummary")}</span>
                                                            </div>
                                                            <div className="space-y-1">
                                                                <h3 className="text-2xl font-black font-vazirmatn text-ink leading-tight">{formData.book.title}</h3>
                                                                <p className="text-sm font-vazirmatn font-bold text-ink/60">{formData.book.author || t("inventory.unknownAuthor")}</p>
                                                            </div>
                                                            <div className="flex items-center gap-4 text-[11px] font-bold text-ink/40">
                                                                <span className="flex items-center gap-1.5"><Hash className="w-3 h-3" /> {formData.book.isbn || t("inventory.noIsbn")}</span>
                                                            </div>
                                                        </div>
                                                        <div className="flex flex-col justify-center gap-4 md:border-l border-ink/5 md:pl-8 rtl:md:border-l-0 rtl:md:border-r rtl:md:pl-0 rtl:md:pr-8">
                                                            {priceScope !== "iraq" && (
                                                            <div className="flex items-center justify-between">
                                                                <span className="text-[10px] font-black text-ink/30 uppercase tracking-widest">{t("common.toman")}</span>
                                                                <span className="text-xl font-black text-primary">{formatNumber(Number(formData.book.priceTomanMashhad || formData.book.priceTomanQom) || 0)}</span>
                                                            </div>
                                                            )}
                                                            {priceScope !== "qom" && priceScope !== "mashhad" && (
                                                            <div className="flex items-center justify-between">
                                                                <span className="text-[10px] font-black text-ink/30 uppercase tracking-widest">{t("common.dinar")}</span>
                                                                <span className="text-xl font-black text-accent">{formatNumber(Number(formData.book.priceDinar) || 0)}</span>
                                                            </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </motion.div>
                                </AnimatePresence>

                                {/* Controls */}
                                <div className="mt-12 flex items-center justify-between pt-8 border-t border-ink/5">
                                    <Button
                                        variant="ghost"
                                        size="lg"
                                        onClick={handleBack}
                                        disabled={currentStep === 0}
                                        className="h-12 rounded-[5px] px-8 text-[11px] font-black uppercase tracking-widest disabled:opacity-30 transition-all hover:bg-white hover:shadow-md active:scale-95 group flex items-center gap-3"
                                    >
                                        <ChevronRight className="w-4 h-4 text-ink/30 group-hover:text-ink/60 transition-transform group-hover:translate-x-1 ltr:rotate-180" />
                                        {t("inventory.wizard.prevStep")}
                                    </Button>

                                    {currentStep === steps.length - 1 ? (
                                        <Button
                                            variant="primary"
                                            size="lg"
                                            onClick={handleFinish}
                                            className="h-14 rounded-[5px] shadow-[0_10px_30px_rgba(32,171,176,0.3)] px-12 font-black text-[12px] uppercase tracking-wider active:scale-95 transition-all hover:shadow-[0_15px_40px_rgba(32,171,176,0.4)] hover:-translate-y-0.5 flex items-center gap-3"
                                        >
                                            <Save className="w-5 h-5" />
                                            {t("inventory.wizard.confirmRegister")}
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="primary"
                                            size="lg"
                                            onClick={handleNext}
                                            className="h-14 rounded-[5px] shadow-[0_10px_25px_rgba(32,171,176,0.2)] px-12 font-black text-[12px] uppercase tracking-wider active:scale-95 transition-all hover:shadow-[0_15px_35px_rgba(32,171,176,0.3)] hover:-translate-y-0.5 group flex items-center gap-3"
                                        >
                                            {t("inventory.wizard.nextStep")}
                                            <ChevronLeft className="w-4 h-4 text-white/60 group-hover:-translate-x-1 transition-transform ltr:rotate-180" />
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>
                </div>

                {/* Live Preview Sidebar */}
                <div className="lg:col-span-5 hidden lg:block">
                    <motion.div
                        variants={sidebarVariants}
                        className="sticky top-8 space-y-6"
                    >


                        {/* Visual Preview Card */}
                        <div className="relative group">
                            <div className="absolute -inset-4 bg-gradient-to-br from-primary/20 via-transparent to-accent/20 rounded-[10px] blur-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-1000" />
                            <div className="relative bg-white/70 backdrop-blur-2xl border border-white rounded-[10px] p-8 shadow-[0_25px_60px_rgba(0,0,0,0.03)] overflow-hidden">
                                {/* Top Decoration */}
                                <div className="absolute top-0 right-0 w-32 h-32 bg-primary/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2" />

                                <div className="space-y-8">
                                    {/* Book Representation */}
                                    <div className="flex justify-center">
                                        <div className="w-48 h-64 bg-gradient-to-br from-ink/[0.02] to-ink/[0.05] rounded-[10px] border border-ink/5 shadow-inner flex items-center justify-center relative overflow-hidden group-hover:border-primary/20 transition-colors duration-500">
                                            <div className="absolute inset-0 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')] opacity-5" />
                                            {formData.book.title ? (
                                                <div className="p-6 text-center space-y-4">
                                                    <div className="w-12 h-12 bg-white rounded-[10px] shadow-sm mx-auto flex items-center justify-center text-primary border border-primary/10">
                                                        <BookIcon className="w-6 h-6" />
                                                    </div>
                                                    <div>
                                                        <p className="font-vazirmatn font-black text-ink text-sm leading-tight line-clamp-2">{formData.book.title}</p>
                                                        <p className="text-[10px] text-ink/40 font-bold mt-2 uppercase">{formData.book.author || t("inventory.author")}</p>
                                                    </div>
                                                </div>
                                            ) : (
                                                <ShoppingBag className="w-12 h-12 text-ink/5" />
                                            )}
                                        </div>
                                    </div>

                                    {/* Real-time Data */}
                                    <div className="space-y-6 border-t border-ink/5 pt-8">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-1">
                                                <p className="text-[8px] font-black text-ink/30 uppercase tracking-widest">{t("inventory.supplier")}</p>
                                                <p className="text-xs font-black text-ink truncate">{formData.supplier?.name || "—"}</p>
                                            </div>
                                            <div className="space-y-1 text-right">
                                                <p className="text-[8px] font-black text-ink/30 uppercase tracking-widest">{t("inventory.type")}</p>
                                                <p className="text-xs font-black text-primary uppercase">{formData.book.type === "consignment" ? t("inventory.consignment") : t("inventory.owned")}</p>
                                            </div>
                                        </div>

                                        <div className="bg-ink/[0.02] rounded-[10px] p-4 space-y-3 border border-ink/[0.03]">
                                            <div className="flex justify-between items-center">
                                                <span className="text-[9px] font-black text-ink/40 uppercase">{t("inventory.addToWarehouse")}</span>
                                                <div className="flex gap-1">
                                                    <div className="w-1.5 h-1.5 rounded-full bg-primary animate-pulse" />
                                                    <div className="w-1.5 h-1.5 rounded-full bg-ink/10" />
                                                </div>
                                            </div>
                                            <div className="flex justify-between items-end">
                                                <p className="text-2xl font-black text-ink tracking-tighter">
                                                    {formatNumber(intakeQty || 0)} <span className="text-xs text-ink/20 font-bold uppercase ml-1">{t("common.quantity")}</span>
                                                </p>
                                                <div className="text-right">
                                                    <p className="text-[9px] font-black text-primary uppercase tracking-widest">{t("inventory.marketPrice")}</p>
                                                    <p className="text-sm font-black text-ink">{formatNumber(Number(formData.book.priceTomanMashhad || formData.book.priceTomanQom || formData.book.priceDinar) || 0)}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Footer Info */}
                                    <div className="flex items-center gap-2 text-ink/20">
                                        <CheckCircle2 className="w-3.5 h-3.5" />
                                        <span className="text-[8px] font-black uppercase tracking-[0.2em]">{t("toast.ledgerSaving")}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Helper Tip */}
                        <div className="p-6 bg-primary/5 rounded-[10px] border border-primary/10 border-dashed">
                            <p className="text-[10px] leading-relaxed text-primary/70 font-bold">
                                {t("inventory.wizard.scannerTip")}
                            </p>
                        </div>
                    </motion.div>
                </div>
            </div>
        </motion.div>
    );
}
