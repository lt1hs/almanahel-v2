<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Branch;
use App\Models\Inventory;
use App\Support\Authorization\BranchAccess;
use App\Support\Catalog\CatalogReadScope;
use App\Support\IntakePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class InventoryController extends Controller
{
    public function intakeInfo(Request $request)
    {
        $user = $request->user();
        $qom = IntakePolicy::qomBranch();
        $warehouse = IntakePolicy::warehouseBranch();
        $iraq = IntakePolicy::iraqBranch();

        return response()->json([
            'can_intake'                 => IntakePolicy::userCanIntake($user, false),
            'can_intake_iraq_only'       => IntakePolicy::userCanIntake($user, true),
            'qom_branch_id'              => $qom?->id,
            'warehouse_branch_id'        => $warehouse?->id,
            'iraq_branch_id'             => $iraq?->id,
            'default_intake_branch_id'   => IntakePolicy::defaultIntakeBranchId($user, false),
            'default_iraq_branch_id'     => IntakePolicy::defaultIntakeBranchId($user, true),
        ]);
    }

    /** Admin: all books with per-branch stock breakdown */
    public function overview(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['super_admin', 'admin'], true)) {
            return response()->json(['message' => 'دسترسی غیرمجاز'], 403);
        }

        $globalThreshold = (int) Cache::get(
            'almanahel.low_stock_threshold',
            config('almanahel.low_stock_threshold', 5)
        );

        $branches = Branch::where('status', 'active')
            ->orderBy('type')
            ->orderBy('name')
            ->orderBy('id')
            ->get([
                'id', 'name', 'type', 'city', 'status',
                'is_central_warehouse', 'is_intake_hub', 'is_iraq_store',
                'supports_dinar', 'supports_toman',
            ])
            ->unique(fn (Branch $b) => mb_strtolower(trim($b->name)))
            ->values();

        $booksQuery = Book::query()
            ->select([
                'id', 'title', 'author', 'isbn', 'publisher', 'size', 'cover',
                'publication_year', 'cover_image', 'weight', 'weight_with_packaging',
                'volume_count', 'category', 'description', 'language', 'iraq_only',
                'low_stock_threshold',
            ])
            ->with([
                'inventories' => function ($q) {
                    $q->select([
                        'id', 'book_id', 'branch_id', 'supplier_id', 'quantity', 'type',
                        'price_toman', 'price_dinar',
                    ])->where('quantity', '>', 0);
                },
                'inventories.branch:id,name,type,city',
                'inventories.supplier:id,name',
            ]);

        $includeZero = $request->boolean('include_zero');

        $books = $booksQuery->get()->map(function (Book $book) use ($globalThreshold) {
            $byBranch = $book->inventories
                ->map(fn ($inv) => [
                    'branch_id'   => $inv->branch_id,
                    'branch_name' => $inv->branch?->name,
                    'branch_type' => $inv->branch?->type,
                    'branch_city' => $inv->branch?->city,
                    'quantity'    => $inv->quantity,
                    'type'        => $inv->type,
                    'supplier'    => $inv->supplier?->name,
                    'price_toman' => $inv->price_toman,
                    'price_dinar' => $inv->price_dinar,
                    'price_band'  => $this->priceBandForBranch($inv->branch),
                ])
                ->values();

            $threshold = $book->low_stock_threshold ?? $globalThreshold;
            $bands = $this->collectPriceBands($book->inventories);

            return [
                'id'                  => $book->id,
                'title'               => $book->title,
                'author'              => $book->author,
                'isbn'                => $book->isbn,
                'publisher'           => $book->publisher,
                'size'                => $book->size,
                'cover'               => $book->cover,
                'publication_year'    => $book->publication_year,
                'cover_image'         => $book->cover_image,
                'weight'              => $book->weight,
                'weight_with_packaging' => $book->weight_with_packaging,
                'volume_count'        => $book->volume_count,
                'category'            => $book->category,
                'description'         => $book->description,
                'language'            => $book->language,
                'iraq_only'           => $book->iraq_only,
                'low_stock_threshold' => $threshold,
                'total_qty'           => $byBranch->sum('quantity'),
                'price_qom'           => $bands['qom'],
                'price_mashhad'       => $bands['mashhad'],
                'price_dinar'         => $bands['najaf'],
                'by_branch'           => $byBranch,
            ];
        })->filter(fn ($b) => $b['total_qty'] > 0 || $includeZero)
          ->values();

        return response()->json([
            'branches'              => $branches,
            'books'                 => $books,
            'low_stock_threshold'   => $globalThreshold,
        ]);
    }

    /** Stock for one book across branches — scoped to caller's authorized branch(es). */
    public function bookByBranches(Request $request, Book $book)
    {
        $user = $request->user();
        BranchAccess::assertIraqBookVisible($user, $book);

        $scope = CatalogReadScope::resolve(
            $user,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            false
        );
        $branchId = $scope->branchId;

        BranchAccess::assertCatalogBookVisible($user, (int) $book->id, $branchId);

        $inventories = Inventory::with(['branch', 'supplier'])
            ->where('book_id', $book->id)
            ->where('branch_id', $branchId)
            ->where('quantity', '>', 0)
            ->get();

        return response()->json([
            'book'        => $book,
            'inventories' => $inventories,
            'total_qty'   => $inventories->sum('quantity'),
        ]);
    }

    /** Same bands as intake: Mashhad POS, Najaf dinar, otherwise Qom toman. */
    private function priceBandForBranch(?Branch $branch): string
    {
        $city = mb_strtolower(trim((string) $branch?->city));
        $name = mb_strtolower(trim((string) $branch?->name));

        if (str_contains($city, 'mashhad') || str_contains($name, 'مشهد')) {
            return 'mashhad';
        }
        if (
            str_contains($city, 'najaf')
            || str_contains($city, 'نجف')
            || str_contains($name, 'نجف')
            || str_contains($city, 'iraq')
            || str_contains($name, 'عراق')
        ) {
            return 'najaf';
        }

        return 'qom';
    }

    private function collectPriceBands($inventories): array
    {
        $bands = ['qom' => null, 'mashhad' => null, 'najaf' => null];

        foreach ($inventories as $inv) {
            $band = $this->priceBandForBranch($inv->branch);
            if ($band === 'najaf') {
                $dinar = (float) ($inv->price_dinar ?? 0);
                if ($dinar > 0 && $bands['najaf'] === null) {
                    $bands['najaf'] = $inv->price_dinar;
                } elseif ($bands['najaf'] === null) {
                    $toman = (float) ($inv->price_toman ?? 0);
                    if ($toman > 0) {
                        $bands['najaf'] = $inv->price_toman;
                    }
                }
                continue;
            }
            $toman = (float) ($inv->price_toman ?? 0);
            if ($toman > 0 && $bands[$band] === null) {
                $bands[$band] = $inv->price_toman;
            }
        }

        return $bands;
    }
}
