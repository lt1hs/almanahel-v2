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
use App\Models\Transfer;
use App\Models\WarehouseLog;
use App\Support\ActivityLogger;
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
        $lite = $request->boolean('lite') || $request->filled('search');

        $query = $lite
            ? Book::query()->select(['id', 'title', 'author', 'isbn', 'iraq_only'])
            : Book::with(['inventories.branch', 'inventories.supplier']);

        // Iraq-only filtering: hide iraq_only books from unauthorized users
        if (!\App\Support\Authorization\BranchAccess::canSeeIraqOnlyBooks($user)) {
            $query->where('iraq_only', false);
        }

        if ($request->has('branch_id')) {
            $query->whereHas('inventories', function ($q) use ($request) {
                $q->where('branch_id', $request->branch_id);
            });
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

        if ($lite) {
            return response()->json($query->orderBy('title')->limit(50)->get());
        }

        return response()->json($query->get());
    }

    /** Barcode / ISBN exact lookup for POS scanner */
    public function byBarcode(Request $request, string $code)
    {
        $user = $request->user();
        $query = Book::with(['inventories.branch', 'inventories.supplier'])
            ->where('isbn', $code);

        if ($user->role !== 'super_admin' && $user->role !== 'admin') {
            $visibleBranches = $user->iraq_only_visible_branches ?? [];
            if (!in_array($user->branch_id, $visibleBranches)) {
                $query->where('iraq_only', false);
            }
        }

        $book = $query->first();
        if (!$book) {
            return response()->json(['message' => 'کتابی با این بارکد یافت نشد'], 404);
        }

        return response()->json($book);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'               => 'required|string|max:255',
            'author'              => 'nullable|string|max:255',
            'isbn'                => 'nullable|string|unique:books,isbn',
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

        $book = Book::create($validated);

        ActivityLogger::record(
            'books',
            'created',
            "ایجاد کتاب «{$book->title}»",
            $book,
            ['isbn' => $book->isbn, 'author' => $book->author],
        );

        return response()->json($book, 201);
    }

    public function uploadCover(Request $request)
    {
        $validated = $request->validate([
            'image' => 'required|image|max:5120',
        ]);

        $path = $validated['image']->store('books/covers', 'public');

        ActivityLogger::record(
            'books',
            'updated',
            'آپلود تصویر جلد کتاب',
            null,
            ['path' => $path],
        );

        return response()->json([
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
        ], 201);
    }

    public function show(Book $book)
    {
        \App\Support\Authorization\BranchAccess::assertIraqBookVisible(request()->user(), $book);

        return response()->json($book->load(['inventories.branch', 'inventories.supplier']));
    }

    public function update(Request $request, Book $book)
    {
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
     * Reasons a book cannot be hard-deleted (inventory / POS / warehouse history).
     *
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

    public function byBranch(Request $request, $branchId)
    {
        $user = $request->user();
        $query = Inventory::with(['book', 'supplier'])
            ->where('branch_id', $branchId);

        if ($user->role !== 'super_admin' && $user->role !== 'admin') {
            $visibleBranches = $user->iraq_only_visible_branches ?? [];
            if (!in_array($user->branch_id, $visibleBranches)) {
                $query->whereHas('book', fn($q) => $q->where('iraq_only', false));
            }
        }

        $inventories = $query->get();
        return response()->json($inventories);
    }

    public function lowStock(Request $request)
    {
        $user = $request->user();
        $globalThreshold = Cache::get('almanahel.low_stock_threshold', config('almanahel.low_stock_threshold', 5));

        $query = Inventory::with(['book', 'branch'])->whereHas('book');

        if ($user->role !== 'super_admin' && $user->role !== 'admin') {
            $visibleBranches = $user->iraq_only_visible_branches ?? [];
            if (!in_array($user->branch_id, $visibleBranches)) {
                $query->whereHas('book', fn ($q) => $q->where('iraq_only', false));
            }
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
