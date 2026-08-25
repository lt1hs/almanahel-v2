<?php

namespace Tests\Feature\Inventory;

use App\Models\Book;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesDomainData;
use Tests\TestCase;

class BookIntakePresentationTest extends TestCase
{
    use CreatesDomainData;
    use RefreshDatabase;

    public function test_core_seed_is_operationally_empty_and_stamps_branch_capabilities(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, Branch::count());
        $this->assertSame(5, User::count());
        $this->assertSame(30, FinancialAccount::count());
        $this->assertSame(0, Book::count());
        $this->assertSame(0, Supplier::count());
        $this->assertSame(0, Inventory::count());

        $this->assertTrue((bool) Branch::where('city', 'قم')->where('type', 'store')->value('is_intake_hub'));
        $this->assertTrue((bool) Branch::where('country', 'عراق')->value('is_iraq_store'));
        $this->assertTrue((bool) Branch::where('country', 'عراق')->value('supports_dinar'));
        $this->assertFalse((bool) Branch::where('country', 'عراق')->value('supports_toman'));
        $this->assertTrue((bool) Branch::where('type', 'warehouse')->value('is_central_warehouse'));
        $this->assertFalse((bool) Branch::where('type', 'warehouse')->value('can_sell'));
    }

    public function test_cover_upload_returns_a_persisted_public_path(): void
    {
        Storage::fake('public');
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);

        $response = $this->post('/api/books/upload-cover', [
            'image' => UploadedFile::fake()->image('cover.jpg', 300, 450),
        ])->assertCreated();

        $path = $response->json('path');
        $this->assertStringStartsWith('books/covers/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_book_show_exposes_current_weighted_lot_cost_when_aggregate_cost_is_empty(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook();
        $inventory = $this->makeInventory($branch, $book, [
            'quantity' => 3,
            'cost_price_toman' => 72500,
            'cost_price_dinar' => null,
        ]);
        $inventory->forceFill(['cost_price_toman' => null])->save();

        $this->getJson('/api/books/'.$book->id.'?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('inventories.0.quantity', 3)
            ->assertJsonPath('inventories.0.cost_price_toman', '72500.00');
    }

    public function test_inventory_overview_contains_saved_book_metadata_and_cover(): void
    {
        $branch = $this->makeBranch();
        $this->actingAsRole('admin', $branch);
        $book = $this->makeBook([
            'publisher' => 'ناشر تست',
            'size' => 'رقعی',
            'cover' => 'سلفون',
            'publication_year' => '1405',
            'cover_image' => 'books/covers/test.jpg',
            'weight' => 350,
            'weight_with_packaging' => 370,
            'volume_count' => 2,
            'description' => 'توضیح کامل',
            'language' => 'fa',
        ]);

        $this->getJson('/api/inventory/overview?include_zero=1')
            ->assertOk()
            ->assertJsonPath('books.0.id', $book->id)
            ->assertJsonPath('books.0.publisher', 'ناشر تست')
            ->assertJsonPath('books.0.cover_image', 'books/covers/test.jpg')
            ->assertJsonPath('books.0.volume_count', 2)
            ->assertJsonPath('books.0.description', 'توضیح کامل');
    }

    public function test_post_books_links_supplier_account_to_catalog(): void
    {
        $branch = $this->makeBranch(['city' => 'قم', 'is_intake_hub' => true]);
        $this->actingAsRole('branch_manager', $branch);

        $account = $this->postJson('/api/supplier-accounts', [
            'branch_id' => $branch->id,
            'display_name' => 'Inline Supplier',
            'type' => 'publisher',
        ])->assertCreated();

        $response = $this->postJson('/api/books', [
            'title' => 'Linked Title',
            'branch_id' => $branch->id,
            'supplier_account_id' => $account->json('id'),
        ])->assertCreated();

        $this->assertDatabaseHas('branch_catalog_items', [
            'branch_id' => $branch->id,
            'book_id' => $response->json('book.id'),
            'local_supplier_account_id' => $account->json('id'),
        ]);
    }

    public function test_iraq_branch_manager_intake_appears_in_warehouse_inventory(): void
    {
        $iraq = $this->makeBranch([
            'name' => 'Iraq Store',
            'city' => 'نجف',
            'country' => 'عراق',
            'is_iraq_store' => true,
            'supports_dinar' => true,
            'supports_toman' => false,
        ]);
        $this->actingAsRole('branch_manager', $iraq);

        $account = $this->postJson('/api/supplier-accounts', [
            'branch_id' => $iraq->id,
            'display_name' => 'Iraq Supplier',
            'type' => 'publisher',
        ])->assertCreated();

        $book = $this->postJson('/api/books', [
            'title' => 'Iraq Visible Book',
            'branch_id' => $iraq->id,
            'supplier_account_id' => $account->json('id'),
        ])->assertCreated();

        $bookId = (int) $book->json('book.id');

        $this->postJson('/api/inventory/purchase', [
            'branch_id' => $iraq->id,
            'book_id' => $bookId,
            'quantity' => 4,
            'currency' => 'dinar',
            'cost_price' => 1000,
            'selling_price' => 1500,
            'supplier_account_id' => $account->json('id'),
        ])->assertCreated();

        $inventory = $this->getJson('/api/warehouse/'.$iraq->id.'/inventory')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($inventory);
        $this->assertSame($bookId, (int) ($inventory[0]['book']['id'] ?? 0));
        $this->assertSame('Iraq Visible Book', $inventory[0]['book']['title'] ?? null);
    }
}
