<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\Inventory;
use App\Services\Notifications\AlertInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

/**
 * @group notifications
 */
class LowStockAlertScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDomainData;

    public function test_pricing_only_zero_qty_rows_do_not_create_low_stock_alerts(): void
    {
        $warehouse = $this->makeBranch([
            'name' => 'انبار مرکزی',
            'type' => 'warehouse',
            'is_central_warehouse' => true,
            'is_intake_hub' => true,
        ]);
        $qom = $this->makeBranch(['name' => 'دارالمناهل قم', 'city' => 'قم']);
        $mashhad = $this->makeBranch(['name' => 'دارالمناهل مشهد', 'city' => 'مشهد']);
        $iraq = $this->makeBranch([
            'name' => 'دارالمناهل عراق',
            'city' => 'نجف',
            'country' => 'عراق',
            'is_iraq_store' => true,
        ]);

        $book = $this->makeBook(['title' => 'test from admin', 'low_stock_threshold' => 5]);

        // Real stock only in warehouse.
        $this->makeInventory($warehouse, $book, ['quantity' => 20]);

        // Hub also wrote sell prices → qty-0 inventory shells at POS branches.
        foreach ([$qom, $mashhad, $iraq] as $branch) {
            Inventory::create([
                'branch_id' => $branch->id,
                'book_id' => $book->id,
                'quantity' => 0,
                'type' => 'owned',
                'price_toman' => $branch->id === $iraq->id ? null : 100000,
                'price_dinar' => $branch->id === $iraq->id ? 2000 : null,
            ]);
        }

        $created = app(AlertInbox::class)->syncStockAndPayables();

        $bodies = AppNotification::query()
            ->where('type', 'low_stock')
            ->where('data->book_id', $book->id)
            ->pluck('body')
            ->all();

        $this->assertSame(0, $created);
        $this->assertCount(0, $bodies);
    }

    public function test_zero_qty_after_real_stock_still_alerts(): void
    {
        $qom = $this->makeBranch(['name' => 'دارالمناهل قم', 'city' => 'قم']);
        $book = $this->makeBook(['title' => 'sold out', 'low_stock_threshold' => 5]);

        // makeInventory creates a stock lot — prior stock existed.
        $inv = $this->makeInventory($qom, $book, ['quantity' => 3]);
        $inv->update(['quantity' => 0]);

        $created = app(AlertInbox::class)->syncStockAndPayables();

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('app_notifications', [
            'type' => 'low_stock',
            'branch_id' => $qom->id,
        ]);
    }

    public function test_purge_removes_existing_pricing_only_false_alarms(): void
    {
        $qom = $this->makeBranch(['name' => 'دارالمناهل قم', 'city' => 'قم']);
        $book = $this->makeBook(['title' => 'ghost']);

        $inv = Inventory::create([
            'branch_id' => $qom->id,
            'book_id' => $book->id,
            'quantity' => 0,
            'type' => 'owned',
            'price_toman' => 50000,
        ]);

        AppNotification::create([
            'dedupe_key' => "low_stock:{$inv->id}:0",
            'type' => 'low_stock',
            'title' => 'موجودی کم',
            'body' => "موجودی «{$book->title}» در «{$qom->name}» به 0 رسید",
            'branch_id' => $qom->id,
            'data' => [
                'inventory_id' => $inv->id,
                'book_id' => $book->id,
                'quantity' => 0,
            ],
        ]);

        $deleted = app(AlertInbox::class)->purgePricingOnlyLowStockAlerts();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('app_notifications', [
            'type' => 'low_stock',
            'branch_id' => $qom->id,
        ]);
    }
}
