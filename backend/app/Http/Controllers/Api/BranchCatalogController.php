<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchCatalogItem;
use App\Support\Authorization\BranchAccess;
use App\Support\Catalog\CatalogReadScope;
use Illuminate\Http\Request;

class BranchCatalogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        BranchAccess::assertCanReadCatalog($user);

        $aggregate = $request->boolean('aggregate');
        if ($aggregate) {
            if (!$request->isMethod('get')) {
                BranchAccess::deny('حالت تجمیعی فقط برای گزارش خواندنی مجاز است', 422);
            }
            BranchAccess::assertCanAggregateCatalog($user);

            $rows = BranchCatalogItem::query()
                ->join('books', 'books.id', '=', 'branch_catalog_items.book_id')
                ->select([
                    'branch_catalog_items.book_id',
                    'branch_catalog_items.source',
                ])
                ->selectRaw('COUNT(DISTINCT branch_catalog_items.branch_id) as branch_count')
                ->where('branch_catalog_items.active', true)
                ->groupBy('branch_catalog_items.book_id', 'branch_catalog_items.source')
                ->orderBy('branch_catalog_items.book_id')
                ->limit(500)
                ->get()
                ->map(fn ($row) => [
                    'book_id' => (int) $row->book_id,
                    'source' => $row->source,
                    'active_branch_count' => (int) $row->branch_count,
                ]);

            return response()->json([
                'aggregate' => true,
                'items' => $rows,
            ]);
        }

        $scope = CatalogReadScope::resolve(
            $user,
            $request->filled('branch_id') ? (int) $request->branch_id : null,
            false
        );
        $branchId = $scope->branchId;

        $query = BranchCatalogItem::query()
            ->with(['book:id,title,author,isbn,iraq_only', 'branch:id,name'])
            ->where('branch_id', $branchId);

        if ($request->filled('source')) {
            $query->where('source', $request->string('source'));
        }
        if ($request->boolean('active_only', true)) {
            $query->where('active', true);
        }
        if ($request->filled('q')) {
            $term = '%'.$request->string('q').'%';
            $query->whereHas('book', function ($book) use ($term) {
                $book->where('title', 'like', $term)
                    ->orWhere('author', 'like', $term)
                    ->orWhere('isbn', 'like', $term);
            });
        }

        $rows = $query->orderBy('book_id')->limit(500)->get();

        return response()->json([
            'aggregate' => false,
            'branch_id' => $branchId,
            'items' => $rows->map(fn (BranchCatalogItem $row) => [
                'id' => $row->id,
                'branch_id' => $row->branch_id,
                'branch_name' => $row->branch?->name,
                'book_id' => $row->book_id,
                'book' => $row->book,
                'source' => $row->source,
                'active' => $row->active,
                'created_at' => $row->created_at,
            ]),
        ]);
    }
}
