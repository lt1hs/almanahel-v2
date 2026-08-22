<?php

namespace App\Console\Commands;

use App\Services\Ledger\T9Preflight;
use Illuminate\Console\Command;

class FinanceT9Preflight extends Command
{
    protected $signature = 'finance:t9-preflight';

    protected $description = 'Read-only T9 cutover checks. Does not modify data.';

    public function handle(T9Preflight $preflight): int
    {
        $issues = $preflight->issues();
        if ($issues === []) {
            $this->info('T9 preflight: no blocking issues.');

            return self::SUCCESS;
        }

        $this->error('T9 preflight failed:');
        foreach ($issues as $issue) {
            $this->line(' - ' . $issue);
        }

        return self::FAILURE;
    }
}
