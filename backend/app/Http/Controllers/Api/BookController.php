<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\ConsignmentReceipt;
use App\Models\ConsignmentReceiptItem;
use App\Models\ConsignmentReturnItem;
use App\Models\CustomerReturnItem;
use App\Models\Gift;
use App\Models\Inventory;
use App\Models\InvoiceItem;
use App\Models\StockLot;
use App\Models\Transfer;
use App\Models\WarehouseLog;
use App\Services\Catalog\BranchCatalogService;
use App\Services\Catalog\CanonicalBookService;
use App\Support\ActivityLogger;
use App\Support\Authorization\BranchAccess;
use App\Support\Catalog\CatalogReadScope;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BookController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $scope = CatalogReadScope::resolve(
            $user,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            $request->boolean('aggregate')
        );
        $lite = $request->boolean('lite') || $request->filled('search');

        if ($scope->aggregate) {
            return response()->json($this->aggregateIndex($request, $lite));
        }

        $branchId = $scope->branchId;
        $catalog = app(BranchCatalogService::class);

        $query = $lite
            ? Book::query()->select(['id', 'title', 'author', 'isbn', 'iraq_only'])
            : Book::query()->with([
                'inventories' => fn ($q) => $q->where('branch_id', $branchId),
                'inventories.branch',
                'inventories.supplier',
            ]);

        if (!BranchAccess::canSeeIraqOnlyBooks($user)) {
            $query->where('iraq_only', false);
        }

        $query->whereIn('id', $catalog->catalogBookIdsQuery($branchId));

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('iraq_only')) {
            $query->where('iraq_only', true);
        }

        if ($lite) {
            return response()->json($query->orderBy('title')->limit(50)->get());
        }

        return response()->json($query->orderBy('title')->get());
    }

    /** @return array<string, mixed> */
    private function aggregateIndex(Request $request, bool $lite): array
    {
        $catalog = app(BranchCatalogService::class);
        $summary = $catalog->aggregateSummaryQuery()->get()->keyBy('book_id');

        $query = Book::query()
            ->whereIn('id', $summary->keys())
            ->orderBy('title');

        if (!BranchAccess::canSeeIraqOnlyBooks($request->user())) {
            $query->where('iraq_only', false);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('iraq_only')) {
            $query->where('iraq_only', true);
        }

        $select = $lite
            ? ['id', 'title', 'author', 'isbn', 'iraq_only']
            : ['id', 'title', 'author', 'isbn', 'publisher', 'iraq_only', 'low_stock_threshold'];

        $books = $query->select($select)
            ->when($lite, fn ($q) => $q->limit(50))
            ->get()
            ->map(fn (Book $book) => array_merge($book->toArray(), [
                'active_branch_count' => (int) ($summary[$book->id]->active_branch_count ?? 0),
            ]));

        return [
            'aggregate' => true,
            'books' => $books,
        ];
    }

    public function byBarcode(Request $request, string $code)
    {
        $user = $request->user();
        $scope = CatalogReadScope::resolve(
            $user,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            false
        );
        $branchId = $scope->branchId;

        $book = Book::query()->where('isbn', $code)->first();
        if (!$book) {
            return response()->json(['message' => 'کتابی با این بارکد یافت نشد'], 404);
        }

        BranchAccess::assertIraqBookVisible($user, $book);
        BranchAccess::assertCatalogBookVisible($user, (int) $book->id, $branchId);

        $book->load([
            'inventories' => fn ($q) => $q->where('branch_id', $branchId),
            'inventories.branch',
            'inventories.supplier',
        ]);

        return response()->json($book);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        BranchAccess::assertCanMutateBranchCatalog($user);

        $validated = $request->validate([
            'title'               => 'required|string|max:255',
            'author'              => 'nullable|string|max:255',
            'isbn'                => 'nullable|string|max:64',
            'publisher'           => 'nullable|string|max:255',
            'size'                => 'nullable|string|max:100',
            'cover'               => 'nullable|string|max:100',
            'publication_year'    => 'nullable|string|max:20',
            'cover_image'         => 'nullable|string|max:500',
            'weight'              => 'nullable|numeric|min:0',
            'weight_with_packaging' => 'nullable|numeric|min:0',
            'volume_count'        => 'nullable|integer|min:1',
            'category'            => 'nullable|string|max:100',
            'description'         => 'nullable|string',
            'language'            => 'nullable|string|max:10',
            'iraq_only'           => 'boolean',
            'low_stock_threshold' => 'nullable|integer|min:1',
            'branch_id'           => 'nullable|exists:branches,id',
            'supplier_account_id' => 'nullable|exists:supplier_accounts,id',
        ]);

        $requestedBranchId = $request->filled('branch_id') ? (int) $validated['branch_id'] : null;
        $supplierAccountId = isset($validated['supplier_account_id']) ? (int) $validated['supplier_account_id'] : null;
        unset($validated['branch_id'], $validated['supplier_account_id']);

        $branchId = BranchAccess::resolveCatalogMutationBranchId($user, $requestedBranchId);

        if ($supplierAccountId !== null) {
            app(BranchCatalogService::class)->assertSupplierAccountInBranch($supplierAccountId, $branchId);
        }

        return DB::transaction(function () use ($validated, $branchId, $user, $supplierAccountId) {
            [$book, $reused] = app(CanonicalBookService::class)->findOrCreate($validated, $user);
            $createdNew = !$reused;

            try {
                $catalogItem = app(BranchCatalogService::class)->ensureForPricing(
                    $branchId,
                    (int) $book->id,
                    $supplierAccountId,
                    $user->id
                );
            } catch (\Throwable $e) {
                if ($createdNew) {
                    $book->delete();
                }

                throw $e;
            }

            ActivityLogger::record(
                'books',
                $reused ? 'linked' : 'created',
                ($reused ? 'پیوند ISBN به کاتالوگ شعبه: ' : 'ایجاد کتاب «')."{$book->title}»",
                $book,
                ['isbn' => $book->isbn, 'author' => $book->author, 'branch_id' => $branchId, 'reused_canonical' => $reused],
            );

            return response()->json([
                'book' => $book,
                'catalog_item' => $catalogItem,
                'reused_canonical' => $reused,
            ], $reused ? 200 : 201);
        });
    }

    public function uploadCover(Request $request)
    {
        BranchAccess::assertCanMutateBranchCatalog($request->user());

        $validated = $request->validate([
            'image' => 'required|file|mimes:jpeg,jpg,png,webp,gif|max:5120',
        ], [
            'image.required' => 'فایل تصویر انتخاب نشده است.',
            'image.file'     => 'فایل ارسالی معتبر نیست. محدودیت upload_max_filesize سرور را بررسی کنید.',
            'image.mimes'    => 'فرمت تصویر باید JPG، PNG، WEBP یا GIF باشد.',
            'image.max'      => 'حداکثر حجم تصویر ۵ مگابایت است.',
        ]);

        $disk = Storage::disk('public');
        if (!$disk->exists('books/covers')) {
            $disk->makeDirectory('books/covers');
        }

        $path = $validated['image']->store('books/covers', 'public');
        if (!$path) {
            Log::error('books.upload_cover.store_failed', [
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'ذخیره تصویر ناموفق بود. دسترسی پوشه storage/app/public را بررسی کنید.',
            ], 500);
        }

        ActivityLogger::record(
            'books',
            'updated',
            'آپلود تصویر جلد کتاب',
            null,
            ['path' => $path],
        );

        return response()->json([
            'path' => $path,
            'url'  => $disk->url($path),
        ], 201);
    }

    public function show(Request $request, Book $book)
    {
        $user = $request->user();
        BranchAccess::assertIraqBookVisible($user, $book);

        $scope = CatalogReadScope::resolve(
            $user,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            false
        );
        $branchId = $scope->branchId;

        if (!BranchAccess::isAdmin($user)) {
            BranchAccess::assertCatalogBookVisible($user, (int) $book->id, $branchId);
        }

        $book->load([
            'inventories' => fn ($q) => BranchAccess::isAdmin($user)
                ? $q
                : $q->where('branch_id', $branchId),
            'inventories.branch',
            'inventories.supplier',
        ]);
        $this->appendCurrentLotCosts($book, $branchId);

        return response()->json($book);
    }

    public function update(Request $request, Book $book)
    {
        BranchAccess::assertCanMutateCanonicalBook($request->user());

        $validated = $request->validate([
            'title'               => 'sometimes|required|string|max:255',
            'author'              => 'sometimes|nullable|string|max:255',
            'isbn'                => 'nullable|string|unique:books,isbn,' . $book->id,
            'publisher'           => 'nullable|string|max:255',
            'size'                => 'nullable|string|max:100',
            'cover'               => 'nullable|string|max:100',
            'publication_year'    => 'nullable|string|max:20',
            'cover_image'         => 'nullable|string|max:500',
            'weight'              => 'nullable|numeric|min:0',
            'weight_with_packaging' => 'nullable|numeric|min:0',
            'volume_count'        => 'nullable|integer|min:1',
            'category'            => 'nullable|string|max:100',
            'description'         => 'nullable|string',
            'language'            => 'nullable|string|max:10',
            'iraq_only'           => 'boolean',
            'low_stock_threshold' => 'nullable|integer|min:1',
        ]);

        $book->update($validated);

        ActivityLogger::record(
            'books',
            'updated',
            "ویرایش کتاب «{$book->title}»",
            $book,
            ['isbn' => $book->isbn, 'author' => $book->author],
        );

        return response()->json($book);
    }

    public function destroy(Request $request, Book $book)
    {
        $user = $request->user();
        if ($user->role !== 'super_admin' && $user->role !== 'admin') {
            return response()->json(['message' => 'فقط مدیر می‌تواند کتاب را حذف کند'], 403);
        }

        $blockers = $this->bookDeleteBlockers($book);
        if (!empty($blockers)) {
            return response()->json([
                'message' => 'این کتاب قابل حذف نیست چون در عملیات فروشگاه/انبار استفاده شده است: ' . implode('، ', $blockers),
                'blockers' => $blockers,
            ], 422);
        }

        return DB::transaction(function () use ($book, $user) {
            $snapshot = [
                'book_id' => $book->id,
                'title'   => $book->title,
                'isbn'    => $book->isbn,
                'author'  => $book->author,
            ];

            $receiptIds = ConsignmentReceiptItem::query()
                ->where('book_id', $book->id)
                ->pluck('consignment_receipt_id')
                ->unique()
                ->values();

            $book->delete();

            foreach ($receiptIds as $receiptId) {
                $receipt = ConsignmentReceipt::query()->find($receiptId);
                $receipt?->recalculateFromItems();
            }

            Log::info('book.deleted', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                ...$snapshot,
            ]);

            ActivityLogger::record(
                'books',
                'deleted',
                "حذف کتاب «{$snapshot['title']}»",
                null,
                $snapshot,
            );

            return response()->json(['message' => 'کتاب با موفقیت حذف شد']);
        });
    }

    /**
     * @return list<string>
     */
    private function bookDeleteBlockers(Book $book): array
    {
        $blockers = [];

        if (Inventory::query()->where('book_id', $book->id)->exists()) {
            $blockers[] = 'موجودی شعب';
        }
        if (InvoiceItem::query()->where('book_id', $book->id)->exists()) {
            $blockers[] = 'فاکتور فروش';
        }
        if (ConsignmentReceiptItem::query()->where('book_id', $book->id)->exists()) {
            $blockers[] = 'رسید امانی';
        }
        if (ConsignmentReturnItem::query()->where('book_id', $book->id)->exists()) {
            $blockers[] = 'برگشت امانی';
        }
        if (CustomerReturnItem::query()->where('book_id', $book->id)->exists()) {
            $blockers[] = 'برگشت مشتری';
        }
        if (Gift::query()->where('book_id', $book->id)->exists()) {
            $blockers[] = 'هدایا';
        }
        if (WarehouseLog::query()->where('book_id', $book->id)->exists()) {
            $blockers[] = 'سوابق انبار';
        }
        if ($this->bookAppearsInTransfers($book->id)) {
            $blockers[] = 'انتقال بین شعب';
        }

        return $blockers;
    }

    private function bookAppearsInTransfers(int $bookId): bool
    {
        return Transfer::query()
            ->whereNotNull('items')
            ->get(['id', 'items'])
            ->contains(function (Transfer $transfer) use ($bookId) {
                return collect($transfer->items ?? [])->contains(
                    fn ($item) => (int) ($item['book_id'] ?? 0) === $bookId
                );
            });
    }

    private function appendCurrentLotCosts(Book $book, ?int $branchId = null): void
    {
        $lotsQuery = StockLot::query()
            ->where('book_id', $book->id)
            ->where('qty_available', '>', 0)
            ->sellable();

        if ($branchId) {
            $lotsQuery->where('branch_id', $branchId);
        }

        $lots = $lotsQuery->get(['branch_id', 'currency', 'unit_cost', 'qty_available']);

        foreach ($book->inventories as $inventory) {
            foreach (['toman', 'dinar'] as $currency) {
                $currencyLots = $lots->where('branch_id', $inventory->branch_id)
                    ->where('currency', $currency);
                $quantity = (int) $currencyLots->sum('qty_available');
                if ($quantity < 1) {
                    continue;
                }

                $total = '0.00';
                foreach ($currencyLots as $lot) {
                    $total = Money::add($total, Money::mul($lot->unit_cost, (int) $lot->qty_available));
                }
                $inventory->setAttribute(
                    $currency === 'toman' ? 'cost_price_toman' : 'cost_price_dinar',
                    bcdiv($total, (string) $quantity, Money::SCALE)
                );
            }
        }
    }

    public function byBranch(Request $request, $branchId)
    {
        $user = $request->user();
        $scope = CatalogReadScope::resolve(
            $user,
            (int) $branchId,
            false
        );

        $catalog = app(BranchCatalogService::class);
        $query = Inventory::with(['book', 'supplier'])
            ->where('branch_id', $scope->branchId)
            ->whereIn('book_id', $catalog->catalogBookIdsQuery($scope->branchId));

        if (!BranchAccess::canSeeIraqOnlyBooks($user)) {
            $query->whereHas('book', fn ($q) => $q->where('iraq_only', false));
        }

        return response()->json($query->get());
    }

    public function lowStock(Request $request)
    {
        $user = $request->user();
        $scope = CatalogReadScope::resolve(
            $user,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            false
        );
        $branchId = $scope->branchId;
        $globalThreshold = Cache::get('almanahel.low_stock_threshold', config('almanahel.low_stock_threshold', 5));

        $catalog = app(BranchCatalogService::class);
        $query = Inventory::with(['book', 'branch'])
            ->where('branch_id', $branchId)
            ->whereIn('book_id', $catalog->catalogBookIdsQuery($branchId))
            ->whereHas('book');

        if (!BranchAccess::canSeeIraqOnlyBooks($user)) {
            $query->whereHas('book', fn ($q) => $q->where('iraq_only', false));
        }

        $inventories = $query->get()
            ->filter(function ($inv) use ($globalThreshold) {
                $threshold = $inv->book->low_stock_threshold ?? $globalThreshold;

                return $inv->quantity <= $threshold;
            })
            ->values();

        return response()->json($inventories);
    }
}
