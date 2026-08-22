<?php

namespace App\Services\Ledger;

use App\Exceptions\DomainException;
use App\Models\FinancialAccount;

final class TreasuryScope
{
    /**
     * @return list<string>
     */
    public static function allowedTypes(string $eventType, string $paymentMethod): array
    {
        return match ($eventType) {
            'sale' => match ($paymentMethod) {
                'cash' => ['cash_drawer'],
                'card' => ['card_clearing', 'bank'],
                'check' => ['checks_receivable'],
                'credit' => ['accounts_receivable'],
                default => throw new DomainException('روش پرداخت فروش برای حساب مالی نامعتبر است'),
            },
            'customer_return' => match ($paymentMethod) {
                'cash' => ['cash_drawer'],
                default => throw new DomainException('روش بازپرداخت برای حساب مالی نامعتبر است'),
            },
            'expense' => ['cash_drawer'],
            'purchase' => ['cash_drawer'],
            'settlement' => match ($paymentMethod) {
                'cash' => ['cash_drawer'],
                'bank_transfer' => ['bank'],
                'check' => ['checks_payable'],
                default => throw new DomainException('روش تسویه برای حساب مالی نامعتبر است'),
            },
            'customer_payment' => match ($paymentMethod) {
                'cash' => ['cash_drawer'],
                'card' => ['card_clearing', 'bank'],
                'bank_transfer' => ['bank'],
                'check' => ['checks_receivable'],
                default => throw new DomainException('روش دریافت از مشتری برای حساب مالی نامعتبر است'),
            },
            'incoming_check_clear' => ['bank'],
            'supplier_check_clear' => ['bank'],
            default => throw new DomainException('نوع رویداد خزانه نامعتبر است'),
        };
    }

    public static function assert(
        FinancialAccount $account,
        string $currency,
        ?int $eventBranchId,
        string $eventType,
        string $paymentMethod
    ): void {
        $account->refresh();
        if (!$account->is_active) {
            throw new DomainException('حساب مالی غیرفعال است');
        }
        if ($account->currency !== $currency) {
            throw new DomainException('ارز حساب مالی با سند یکسان نیست');
        }
        if ($eventBranchId === null) {
            if ($account->branch_id !== null) {
                throw new DomainException('رویداد بدون شعبه باید از حساب شرکتی استفاده کند');
            }
        } else {
            if ($account->branch_id === null) {
                throw new DomainException('رویداد شعبه‌ای نمی‌تواند از حساب شرکتی استفاده کند');
            }
            if ((int) $account->branch_id !== $eventBranchId) {
                throw new DomainException('حساب مالی متعلق به شعبه دیگری است');
            }
        }

        $allowed = self::allowedTypes($eventType, $paymentMethod);
        if (!in_array($account->type, $allowed, true)) {
            throw new DomainException('نوع حساب مالی با روش پرداخت این رویداد سازگار نیست');
        }
    }
}
