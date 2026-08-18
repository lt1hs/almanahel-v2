<?php

namespace App\Console\Commands;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LedgerReconcile extends Command
{
    protected $signature = 'ledger:reconcile';
    protected $description = 'Verify journal entries are balanced per currency';

    public function handle(): int
    {
        $unbalanced = 0;
        JournalEntry::query()->orderBy('id')->chunkById(50, function ($entries) use (&$unbalanced) {
            foreach ($entries as $entry) {
                $totals = JournalLine::where('journal_entry_id', $entry->id)
                    ->select('currency', DB::raw('SUM(debit) as d'), DB::raw('SUM(credit) as c'))
                    ->groupBy('currency')
                    ->get();
                foreach ($totals as $row) {
                    if (round((float) $row->d, 2) !== round((float) $row->c, 2)) {
                        $unbalanced++;
                        $this->error("Entry {$entry->id} {$row->currency}: debit={$row->d} credit={$row->c}");
                    }
                }
            }
        });

        $this->info($unbalanced === 0 ? 'All journal entries balanced' : "Unbalanced: {$unbalanced}");

        $orphans = JournalEntry::whereNotNull('source_type')->whereNotNull('source_id')->count();
        $this->info("Journals: {$orphans}");

        return $unbalanced === 0 ? self::SUCCESS : self::FAILURE;
    }
}
