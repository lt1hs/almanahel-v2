<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkOverdueReceivables extends Command
{
    protected $signature = 'receivables:mark-overdue';
    protected $description = 'Mark past-due pending credit invoices as overdue (checks remain pending until cleared/bounced; overdue is date-based)';

    public function handle(): int
    {
        $today = now()->toDateString();
        $credits = Invoice::where('payment_method', 'credit')
            ->where('payment_status', 'pending')
            ->whereDate('due_date', '<', $today)
            ->update(['payment_status' => 'overdue']);

        $this->info("Credits marked overdue: {$credits}");

        return self::SUCCESS;
    }
}
