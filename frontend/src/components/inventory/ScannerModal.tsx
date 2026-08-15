"use client";

import React, { useEffect, useRef, useState } from "react";
import { X, Camera } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { useTranslation } from "@/hooks/useTranslation";

interface ScannerModalProps {
  isOpen: boolean;
  onClose: () => void;
  onDetected: (code: string) => void;
}

export function ScannerModal({ isOpen, onClose, onDetected }: ScannerModalProps) {
  const { t } = useTranslation();
  const scannerRef = useRef<HTMLDivElement>(null);
  const [error, setError] = useState<string | null>(null);
  const onDetectedRef = useRef(onDetected);
  onDetectedRef.current = onDetected;

  useEffect(() => {
    if (!isOpen) return;

    let active = true;
    setError(null);

    (async () => {
      const Quagga = (await import("@ericblade/quagga2")).default;
      if (!active || !scannerRef.current) return;

      Quagga.init(
        {
          inputStream: {
            type: "LiveStream",
            target: scannerRef.current,
            constraints: {
              width: 640,
              height: 480,
              facingMode: "environment",
            },
          } as never,
          locator: {
            patchSize: "medium",
            halfSample: true,
          },
          numOfWorkers: 2,
          decoder: {
            readers: ["ean_reader", "ean_8_reader", "code_128_reader", "upc_reader"],
          },
          locate: true,
        },
        (err) => {
          if (!active) return;
          if (err) {
            console.error(err);
            setError(t("inventory.scanner.cameraDenied"));
            return;
          }
          Quagga.start();
        }
      );

      Quagga.onDetected((result) => {
        if (result.codeResult.code) {
          Quagga.stop();
          onDetectedRef.current(result.codeResult.code);
          onClose();
        }
      });
    })();

    return () => {
      active = false;
      import("@ericblade/quagga2")
        .then((mod) => {
          try {
            mod.default.offDetected();
            mod.default.stop();
          } catch {
            /* already stopped */
          }
        })
        .catch(() => {});
    };
  }, [isOpen, onClose, t]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink/80 backdrop-blur-sm p-4">
      <div className="bg-parchment w-full max-w-lg rounded-2xl overflow-hidden shadow-2xl border border-ink/10">
        <div className="flex items-center justify-between p-4 border-b border-ink/5">
          <div className="flex items-center gap-2">
            <Camera className="w-5 h-5 text-primary" />
            <h3 className="font-bold text-ink">{t("inventory.scanner.title")}</h3>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-2 hover:bg-ink/5 rounded-full transition-colors"
          >
            <X className="w-5 h-5 text-ink/60" />
          </button>
        </div>

        <div className="relative aspect-[4/3] bg-ink overflow-hidden">
          <div ref={scannerRef} className="w-full h-full" />
          {error ? (
            <div className="absolute inset-0 flex items-center justify-center bg-ink/90 p-6 text-center">
              <p className="text-white text-sm">{error}</p>
            </div>
          ) : (
            <p className="absolute bottom-3 inset-x-0 text-center text-white/70 text-xs">
              {t("inventory.scanner.hint")}
            </p>
          )}
        </div>

        <div className="p-4 flex justify-center">
          <Button type="button" variant="outline" onClick={onClose}>
            {t("common.cancel")}
          </Button>
        </div>
      </div>
    </div>
  );
}
