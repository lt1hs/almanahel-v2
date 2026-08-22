<?php

namespace App\Console\Commands;

use App\Services\Catalog\BranchCatalogBackfill;
use Illuminate\Console\Command;

class CatalogListLegacyUnknown extends Command
{
    protected $signature = 'catalog:list-legacy-unknown {--branch_id=} {--json : JSON output}';

    protected $description = 'List branch catalog items with legacy_unknown provenance for admin reconciliation';

    public function handle(BranchCatalogBackfill $backfill): int
    {
        $branchId = $this->option('branch_id') !== null && $this->option('branch_id') !== ''
            ? (int) $this->option('branch_id')
            : null;

        $rows = $backfill->listLegacyUnknown($branchId);

        if ($this->option('json')) {
            $this->line(json_encode(['count' => count($rows), 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('legacy_unknown catalog items: '.count($rows));
        if ($rows === []) {
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Branch', 'Book', 'ISBN', 'Active'],
            array_map(fn (array $row) => [
                $row['id'],
                $row['branch_name'] ?? $row['branch_id'],
                $row['title'] ?? $row['book_id'],
                $row['isbn'] ?? '—',
                $row['active'] ? 'yes' : 'no',
            ], $rows)
        );

        return self::SUCCESS;
    }
}
