<?php

namespace App\Services\Catalog;

use App\Models\BranchCatalogItem;
use App\Models\ConsignmentReceiptItem;
use App\Models\Gift;
use App\Models\Inventory;
use App\Models\InvoiceItem;
use App\Models\StockLot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BranchCatalogBackfill
{
    public function __construct(
        private readonly BranchCatalogService $catalog
    ) {}

    /**
     * @return array{
     *   pairs_discovered: int,
     *   created: int,
     *   skipped_existing: int,
     *   by_source: array<string, int>
     * }
     */
    public function run(bool $apply = false): array
    {
        $pairs = $this->discoverPairs();
        $created = 0;
        $skipped = 0;
        $bySource = [];

        foreach ($pairs as $pair) {
            $branchId = (int) $pair['branch_id'];
            $bookId = (int) $pair['book_id'];

            if (BranchCatalogItem::query()->where('branch_id', $branchId)->where('book_id', $bookId)->exists()) {
                $skipped++;
                continue;
            }

            $source = $this->catalog->inferBackfillSource($branchId, $bookId);
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;

            if ($apply) {
                $this->catalog->ensure($branchId, $bookId, $source);
                $created++;
            }
        }

        return [
            'pairs_discovered' => $pairs->count(),
            'created' => $created,
            'skipped_existing' => $skipped,
            'by_source' => $bySource,
        ];
    }

    /** @return Collection<int, array{branch_id: int, book_id: int}> */
    private function discoverPairs(): Collection
    {
        $pairs = collect();

        StockLot::query()
            ->select(['branch_id', 'book_id'])
            ->distinct()
            ->orderBy('branch_id')
            ->orderBy('book_id')
            ->chunk(500, function ($rows) use ($pairs) {
                foreach ($rows as $row) {
                    $pairs->push(['branch_id' => (int) $row->branch_id, 'book_id' => (int) $row->book_id]);
                }
            });

        Inventory::query()
            ->select(['branch_id', 'book_id'])
            ->whereNull('superseded_by_inventory_id')
            ->distinct()
            ->orderBy('branch_id')
            ->orderBy('book_id')
            ->chunk(500, function ($rows) use ($pairs) {
                foreach ($rows as $row) {
                    $pairs->push(['branch_id' => (int) $row->branch_id, 'book_id' => (int) $row->book_id]);
                }
            });

        Gift::query()
            ->select(['branch_id', 'book_id'])
            ->distinct()
            ->orderBy('branch_id')
            ->orderBy('book_id')
            ->chunk(500, function ($rows) use ($pairs) {
                foreach ($rows as $row) {
                    $pairs->push(['branch_id' => (int) $row->branch_id, 'book_id' => (int) $row->book_id]);
                }
            });

        ConsignmentReceiptItem::query()
            ->join('consignment_receipts', 'consignment_receipts.id', '=', 'consignment_receipt_items.consignment_receipt_id')
            ->select(['consignment_receipts.branch_id', 'consignment_receipt_items.book_id'])
            ->distinct()
            ->orderBy('consignment_receipts.branch_id')
            ->orderBy('consignment_receipt_items.book_id')
            ->chunk(500, function ($rows) use ($pairs) {
                foreach ($rows as $row) {
                    $pairs->push(['branch_id' => (int) $row->branch_id, 'book_id' => (int) $row->book_id]);
                }
            });

        InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->select(['invoices.branch_id', 'invoice_items.book_id'])
            ->distinct()
            ->orderBy('invoices.branch_id')
            ->orderBy('invoice_items.book_id')
            ->chunk(500, function ($rows) use ($pairs) {
                foreach ($rows as $row) {
                    if ($row->branch_id) {
                        $pairs->push(['branch_id' => (int) $row->branch_id, 'book_id' => (int) $row->book_id]);
                    }
                }
            });

        return $pairs
            ->unique(fn (array $pair) => $pair['branch_id'].':'.$pair['book_id'])
            ->values();
    }

    /** @return list<array<string, mixed>> */
    public function listLegacyUnknown(?int $branchId = null): array
    {
        $query = BranchCatalogItem::query()
            ->with(['branch:id,name', 'book:id,title,isbn'])
            ->where('source', 'legacy_unknown')
            ->orderBy('branch_id')
            ->orderBy('book_id');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get()->map(fn (BranchCatalogItem $row) => [
            'id' => $row->id,
            'branch_id' => $row->branch_id,
            'branch_name' => $row->branch?->name,
            'book_id' => $row->book_id,
            'title' => $row->book?->title,
            'isbn' => $row->book?->isbn,
            'active' => $row->active,
            'created_at' => $row->created_at?->toIso8601String(),
        ])->all();
    }
}
