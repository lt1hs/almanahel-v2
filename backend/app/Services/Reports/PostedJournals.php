<?php

namespace App\Services\Reports;

use App\Exceptions\DomainException;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single posted-journal query surface. Reversed originals stay in the sums
 * together with their reversal entries.
 */
class PostedJournals
{
    /** Statuses that are not part of accounting history. */
    public const EXCLUDED_STATUSES = ['draft', 'void'];

    /**
     * @return array{from: string, to: string, start: Carbon, end: Carbon}
     */
    public function period(?string $dateFrom, ?string $dateTo, ?string $defaultFrom = null, ?string $defaultTo = null): array
    {
        $tz = (string) config('app.timezone', 'UTC');
        $from = $dateFrom ?: ($defaultFrom ?: now($tz)->startOfMonth()->toDateString());
        $to = $dateTo ?: ($defaultTo ?: now($tz)->toDateString());
        if ($from > $to) {
            throw new DomainException('بازه تاریخ نامعتبر است: date_from باید کوچکتر یا مساوی date_to باشد', 422);
        }

        return [
            'from' => $from,
            'to' => $to,
            'start' => Carbon::parse($from, $tz)->startOfDay(),
            'end' => Carbon::parse($to, $tz)->endOfDay(),
        ];
    }

    public function currency(?string $currency, string $default = 'toman'): string
    {
        $value = $currency ?: $default;
        if (!in_array($value, ['toman', 'dinar'], true)) {
            throw new DomainException('ارز باید toman یا dinar باشد', 422);
        }

        return $value;
    }

    public function baseQuery(): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where(function ($q) {
                $q->whereNull('journal_entries.status')
                    ->orWhereNotIn('journal_entries.status', self::EXCLUDED_STATUSES);
            });
    }

    public function applyOccurredAt(Builder $query, ?Carbon $from, ?Carbon $to, string $mode = 'between'): Builder
    {
        if ($mode === 'before' && $from) {
            return $query->where('journal_entries.occurred_at', '<', $from);
        }
        if ($mode === 'through' && $to) {
            return $query->where('journal_entries.occurred_at', '<=', $to);
        }
        if ($from && $to) {
            return $query->whereBetween('journal_entries.occurred_at', [$from, $to]);
        }

        return $query;
    }

    public function applyScope(
        Builder $query,
        string $currency,
        ?int $branchId,
        bool $includeCorporate = false,
        ?array $branchIds = null
    ): Builder {
        $query->where('journal_lines.currency', $currency);
        if ($branchId !== null) {
            $query->where('journal_lines.branch_id', $branchId);
        } elseif ($branchIds !== null) {
            $query->whereIn('journal_lines.branch_id', $branchIds ?: [0]);
        } elseif (!$includeCorporate) {
            $query->whereNotNull('journal_lines.branch_id');
        }

        return $query;
    }

    /**
     * @return array{debit: string, credit: string}
     */
    public function sums(Builder $query): array
    {
        $row = (clone $query)->selectRaw($this->sumSelect())->first();

        return [
            'debit' => Money::of($row->debit ?? 0),
            'credit' => Money::of($row->credit ?? 0),
        ];
    }

    /**
     * @param  list<string>  $group
     * @return list<object>
     */
    public function grouped(Builder $query, array $group): array
    {
        $select = array_merge($group, [DB::raw($this->sumSelect())]);
        $rows = (clone $query)->select($select)->groupBy($group)->get();

        return $rows->all();
    }

    public function assetBalance(string $debit, string $credit): string
    {
        return Money::sub($debit, $credit);
    }

    public function liabilityBalance(string $debit, string $credit): string
    {
        return Money::sub($credit, $debit);
    }

    public function revenueNet(string $debit, string $credit): string
    {
        return Money::sub($credit, $debit);
    }

    public function expenseNet(string $debit, string $credit): string
    {
        return Money::sub($debit, $credit);
    }

    private function sumSelect(): string
    {
        $cast = DB::connection()->getDriverName() === 'sqlite' ? 'TEXT' : 'CHAR';

        return "CAST(COALESCE(SUM(journal_lines.debit), 0) AS {$cast}) as debit, CAST(COALESCE(SUM(journal_lines.credit), 0) AS {$cast}) as credit";
    }
}
