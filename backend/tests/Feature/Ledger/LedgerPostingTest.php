<?php

namespace Tests\Feature\Ledger;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/** @group ledger */
class LedgerPostingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_cash_sale_posts_balanced_journal(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $this->makeInventory($branch, $book, [
            'quantity' => 5,
            'price_toman' => 100000,
            'cost_price_toman' => 70000,
        ]);

        $this->postJson('/api/invoices', [
            'branch_id' => $branch->id,
            'payment_method' => 'cash',
            'currency' => 'toman',
            'items' => [['book_id' => $book->id, 'quantity' => 1, 'actual_price' => 100000]],
        ])->assertCreated();

        $entry = JournalEntry::latest('id')->first();
        $this->assertNotNull($entry);
        $debit = (float) JournalLine::where('journal_entry_id', $entry->id)->sum('debit');
        $credit = (float) JournalLine::where('journal_entry_id', $entry->id)->sum('credit');
        $this->assertEquals(round($debit, 2), round($credit, 2));
    }
}
