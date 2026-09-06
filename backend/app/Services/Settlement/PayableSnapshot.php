<?php

namespace App\Services\Settlement;

use App\Exceptions\DomainException;
use App\Models\StockLot;
use App\Support\Money;

/**
 * Unused by HTTP controllers until T9. Stamped rows must never read Cache or live config.
 */
class PayableSnapshot
{
    public const BASIS_FULL_UNIT_COST = 'full_unit_cost';
    public const BASIS_PERCENTAGE_OF_UNIT_COST = 'percentage_of_unit_cost';
    public const BASIS_PERCENTAGE_OF_NET_SALE = 'percentage_of_net_sale';

    public function compute(mixed $grossCost, string $basis, mixed $rate): string
    {
        $gross = Money::of($grossCost);
        $normalizedRate = Money::of($rate);

        return match ($basis) {
            self::BASIS_FULL_UNIT_COST => $gross,
            self::BASIS_PERCENTAGE_OF_UNIT_COST => Money::mul($gross, $normalizedRate),
            default => throw new DomainException('قاعده بدهی تأمین‌کننده ناشناخته است'),
        };
    }

    public function remaining(mixed $fullPayable, mixed $alreadySettled): string
    {
        return Money::max('0', Money::sub($fullPayable, $alreadySettled));
    }

    /**
     * @return array{payable_basis: string, payable_rate: string, gross_cost: string, publisher_payable: string, rule_source: string}
     */
    public function fromStampedLot(StockLot $lot, int $quantity, mixed $unitCost = null): array
    {
        if ($lot->payable_basis === null || $lot->payable_basis === ''
            || $lot->payable_rate === null || $lot->payable_rate === '') {
            throw new DomainException('لات امانی مُهرشده نیست؛ از forNewIntake یا inferLegacy استفاده کنید');
        }

        $cost = $unitCost ?? ((string) $lot->ownership_type === 'consignment'
            ? $lot->effectivePayableUnitCost()
            : $lot->unit_cost);

        return $this->snapshot(
            $cost,
            $quantity,
            (string) $lot->payable_basis,
            $lot->payable_rate,
            (string) ($lot->payable_rule_source ?: 'intake')
        );
    }

    /**
     * Explicit new-intake default (full unit cost). Never use for unstamped history.
     *
     * @return array{payable_basis: string, payable_rate: string, gross_cost: string, publisher_payable: string, rule_source: string}
     */
    public function forNewIntake(StockLot $lot, int $quantity): array
    {
        $basis = (string) config('almanahel.consignment_payable.default_basis', self::BASIS_FULL_UNIT_COST);
        $rate = config('almanahel.consignment_payable.default_rate', '1.0');

        return $this->snapshot($lot->unit_cost, $quantity, $basis, $rate, 'intake');
    }

    /**
     * @return array{payable_basis: string, payable_rate: string, gross_cost: string, publisher_payable: string, rule_source: string}
     */
    public function inferLegacy(mixed $unitCost, int $quantity, mixed $historicalPayable): array
    {
        $gross = Money::mul($unitCost, $quantity);
        $payable = Money::of($historicalPayable);

        return [
            'payable_basis' => self::BASIS_PERCENTAGE_OF_UNIT_COST,
            'payable_rate' => Money::isZero($gross) ? '0.00' : bcdiv($payable, $gross, 4),
            'gross_cost' => $gross,
            'publisher_payable' => $payable,
            'rule_source' => 'legacy_inferred',
        ];
    }

    /**
     * @param  array{publisher_payable: mixed}  $stamped
     */
    public function fromStampedRow(array $stamped): string
    {
        if (!array_key_exists('publisher_payable', $stamped) || $stamped['publisher_payable'] === null) {
            throw new DomainException('سطر بدهی مُهرشده publisher_payable ندارد');
        }

        return Money::of($stamped['publisher_payable']);
    }

    /**
     * @return array{payable_basis: string, payable_rate: string, gross_cost: string, publisher_payable: string, rule_source: string}
     */
    public function snapshotFull(mixed $unitCost, int $quantity): array
    {
        return $this->snapshot(
            $unitCost,
            $quantity,
            self::BASIS_FULL_UNIT_COST,
            '1.0000',
            'restatement'
        );
    }

    /**
     * @return array{payable_basis: string, payable_rate: string, gross_cost: string, publisher_payable: string, rule_source: string}
     */
    private function snapshot(mixed $unitCost, int $quantity, string $basis, mixed $rate, string $source): array
    {
        $gross = Money::mul($unitCost, $quantity);
        $payable = $this->compute($gross, $basis, $rate);

        return [
            'payable_basis' => $basis,
            'payable_rate' => Money::of($rate),
            'gross_cost' => $gross,
            'publisher_payable' => $payable,
            'rule_source' => $source,
        ];
    }
}
