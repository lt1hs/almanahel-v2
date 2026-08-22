<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BranchCatalogItem;
use App\Support\Authorization\BranchAccess;
use Illuminate\Http\Request;

class BranchCatalogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $aggregate = $request->boolean('aggregate');

        if ($aggregate) {
            if (!$request->isMethod('get')) {
                BranchAccess::deny('حالت تجمیعی فقط برای گزارش خواندنی مجاز است', 422);
            }
            BranchAccess::assertCanAggregateCatalog($user);
        } else {
            $branchId = BranchAccess::resolveCatalogBranchId(
                $user,
                $request->filled('branch_id') ? (int) $request->branch_id : null
            );
            if (!$branchId) {
                return response()->json([
                    'message' => 'انتخاب شعبه الزامی است',
                    'error' => 'branch_required',
                ], 422);
            }
        }

        $query = BranchCatalogItem::query()
            ->with(['book:id,title,author,isbn,iraq_only', 'branch:id,name', 'localSupplierAccount:id,display_name']);

        if (!$aggregate) {
            $branchId = BranchAccess::resolveCatalogBranchId(
                $user,
                $request->filled('branch_id') ? (int) $request->branch_id : null
            );
            $query->where('branch_id', $branchId);
        }

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

        $rows = $query->orderBy('branch_id')->orderBy('book_id')->limit(500)->get();

        return response()->json($rows->map(fn (BranchCatalogItem $row) => [
            'id' => $row->id,
            'branch_id' => $row->branch_id,
            'branch_name' => $row->branch?->name,
            'book_id' => $row->book_id,
            'book' => $row->book,
            'source' => $row->source,
            'local_supplier_account_id' => $row->local_supplier_account_id,
            'local_supplier_account' => $row->localSupplierAccount,
            'active' => $row->active,
            'price_toman' => $row->price_toman,
            'price_dinar' => $row->price_dinar,
            'created_at' => $row->created_at,
        ]));
    }
}
