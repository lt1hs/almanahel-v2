<?php

namespace App\Console\Commands;

use App\Models\Inventory;
use App\Models\StockLot;
use Illuminate\Console\Command;

class ReconcileStockLots extends Command
{
    protected $signature = 'stock:reconcile-lots';
    protected $description = 'Report inventory aggregates that disagree with stock lot totals';

    public function handle(): int
    {
        $mismatches = 0;
        Inventory::query()->whereNull('superseded_by_inventory_id')->orderBy('id')->chunkById(100, function ($rows) use (&$mismatches) {
            foreach ($rows as $inv) {
                $sum = (int) StockLot::where('branch_id', $inv->branch_id)
                    ->where('book_id', $inv->book_id)
                    ->sum('qty_available');
                if ($sum !== (int) $inv->quantity) {
                    $mismatches++;
                    $this->warn("branch={$inv->branch_id} book={$inv->book_id} inv={$inv->quantity} lots={$sum}");
                }
            }
        });

        $uncertain = StockLot::where('legacy_uncertain', true)->count();
        $this->info("Mismatches: {$mismatches}; legacy_uncertain lots: {$uncertain}");

        return $mismatches === 0 ? self::SUCCESS : self::FAILURE;
    }
}
