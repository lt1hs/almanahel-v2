<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;

final class InvoiceTotalGuard
{
    public function assert(Invoice $invoice): void
    {
        $items = InvoiceItem::query()->where('invoice_id', $invoice->id)->lockForUpdate()->get();
        if ($items->isEmpty()) {
            throw new DomainException('فروش بدون قلم قابل ثبت در دفتر نیست');
        }

        $gross = '0.00';
        $itemDiscount = '0.00';
        foreach ($items as $item) {
            $qty = (int) $item->quantity;
            if ($qty <= 0) {
                throw new DomainException('مقدار قلم فاکتور نامعتبر است');
            }
            $unit = $item->actual_price !== null && $item->actual_price !== ''
                ? $item->actual_price
                : $item->unit_price;
            $price = Money::of($unit);
            $discount = Money::of($item->discount ?? 0);
            if (Money::isNegative($price) || Money::isNegative($discount)) {
                throw new DomainException('مبالغ فاکتور نمی‌توانند منفی باشند');
            }
            if (Money::cmp($discount, $price) > 0) {
                throw new DomainException('تخفیف قلم از قیمت فروش بیشتر است');
            }
            $gross = Money::add($gross, Money::mul($price, $qty));
            $itemDiscount = Money::add($itemDiscount, Money::mul($discount, $qty));
        }

        $headerDiscount = Money::of($invoice->discount_amount ?? 0);
        if (Money::cmp($headerDiscount, $itemDiscount) !== 0) {
            throw new DomainException('تخفیف فاکتور با مجموع تخفیف اقلام برابر نیست');
        }

        $net = Money::max('0', Money::sub($gross, $itemDiscount));
        if (Money::cmp($invoice->subtotal, $gross) !== 0) {
            throw new DomainException('جمع جزء فاکتور با اقلام ثبت‌شده برابر نیست');
        }
        if (Money::isZero($net) || Money::isNegative($net)) {
            throw new DomainException('مبلغ خالص فروش نامعتبر است');
        }
        if (Money::cmp($invoice->total, $net) !== 0) {
            throw new DomainException('مبلغ خالص فاکتور با اقلام ثبت‌شده برابر نیست');
        }
    }
}
