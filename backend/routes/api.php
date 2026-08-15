<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ConsignmentController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\GiftController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\BookCategoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Public Routes
Route::post('/login', [AuthController::class, 'login']);

// Protected Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user',    [AuthController::class, 'user']);

    // ─── Books ─────────────────────────────────────────────────
    Route::get('/books',               [BookController::class, 'index']);
    Route::post('/books',              [BookController::class, 'store']);
    Route::post('/books/upload-cover', [BookController::class, 'uploadCover']);
    Route::get('/books/low-stock',     [BookController::class, 'lowStock']);
    Route::get('/books/by-barcode/{code}', [BookController::class, 'byBarcode']);
    Route::get('/books/{book}',        [BookController::class, 'show']);
    Route::put('/books/{book}',        [BookController::class, 'update']);
    Route::delete('/books/{book}',     [BookController::class, 'destroy']);
    Route::get('/branches/{branchId}/books', [BookController::class, 'byBranch']);

    // ─── Branches ──────────────────────────────────────────────
    Route::get('/branches',              [BranchController::class, 'index']);
    Route::post('/branches',             [BranchController::class, 'store']);
    Route::get('/branches/{branch}',     [BranchController::class, 'show']);
    Route::put('/branches/{branch}',     [BranchController::class, 'update']);
    Route::delete('/branches/{branch}',  [BranchController::class, 'destroy']);
    Route::get('/branches/{branch}/profit', [BranchController::class, 'profit']);

    // ─── Suppliers ─────────────────────────────────────────────
    Route::get('/suppliers',               [SupplierController::class, 'index']);
    Route::post('/suppliers',              [SupplierController::class, 'store']);
    Route::get('/suppliers/{supplier}',    [SupplierController::class, 'show']);
    Route::put('/suppliers/{supplier}',    [SupplierController::class, 'update']);
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
    Route::get('/suppliers/{supplier}/balance', [SupplierController::class, 'balance']);

    // ─── Invoices & Sales ──────────────────────────────────────
    Route::get('/invoices',             [InvoiceController::class, 'index']);
    Route::post('/invoices',            [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}',   [InvoiceController::class, 'show']);
    Route::get('/checks',               [InvoiceController::class, 'checks']);
    Route::put('/checks/{check}',       [InvoiceController::class, 'updateCheck']);
    Route::get('/credits',              [InvoiceController::class, 'credits']);
    Route::put('/credits/{invoice}',    [InvoiceController::class, 'updateCredit']);

    // ─── Consignment ───────────────────────────────────────────
    Route::get('/consignments',                    [ConsignmentController::class, 'index']);
    Route::post('/consignments',                   [ConsignmentController::class, 'store']);
    Route::get('/consignments/unsettled-by-supplier', [ConsignmentController::class, 'unsettledBySupplier']);
    Route::get('/consignments/settlement-preview', [ConsignmentController::class, 'settlementPreview']);
    Route::get('/consignments/settlements',        [ConsignmentController::class, 'settlements']);
    Route::post('/consignments/settle',            [ConsignmentController::class, 'settle']);
    Route::post('/consignments/settle-bulk',       [ConsignmentController::class, 'settleBulk']);
    Route::post('/consignments/{consignmentReceipt}/close', [ConsignmentController::class, 'close']);
    Route::get('/consignments/{consignmentReceipt}', [ConsignmentController::class, 'show']);

    // ─── Returns ───────────────────────────────────────────────
    Route::get('/returns/customer',  [ReturnController::class, 'customerReturns']);
    Route::post('/returns/customer', [ReturnController::class, 'createCustomerReturn']);
    Route::get('/returns/consignment',  [ReturnController::class, 'consignmentReturns']);
    Route::post('/returns/consignment', [ReturnController::class, 'createConsignmentReturn']);

    // ─── Gifts ─────────────────────────────────────────────────
    Route::get('/gifts',            [GiftController::class, 'index']);
    Route::post('/gifts',           [GiftController::class, 'store']);
    Route::get('/gifts/{gift}',     [GiftController::class, 'show']);
    Route::put('/gifts/{gift}/status', [GiftController::class, 'updateStatus']);

    // ─── Transfers ─────────────────────────────────────────────
    Route::get('/transfers',                [TransferController::class, 'index']);
    Route::post('/transfers',               [TransferController::class, 'store']);
    Route::get('/transfers/{transfer}',     [TransferController::class, 'show']);
    Route::put('/transfers/{transfer}/status', [TransferController::class, 'updateStatus']);

    // ─── Inventory overview & intake ───────────────────────────
    Route::get('/inventory/intake-info',        [InventoryController::class, 'intakeInfo']);
    Route::get('/inventory/overview',           [InventoryController::class, 'overview']);
    Route::get('/inventory/books/{book}/branches', [InventoryController::class, 'bookByBranches']);

    // ─── Warehouse ─────────────────────────────────────────────
    Route::get('/warehouse/logs',              [WarehouseController::class, 'index']);
    Route::get('/warehouse/logs/{warehouseLog}', [WarehouseController::class, 'show']);
    Route::post('/warehouse/logs',             [WarehouseController::class, 'store']);
    Route::put('/warehouse/logs/{warehouseLog}', [WarehouseController::class, 'updateLog']);
    Route::get('/warehouse/{branchId}/inventory', [WarehouseController::class, 'inventory']);
    Route::get('/warehouse/{branchId}/stats',     [WarehouseController::class, 'stats']);
    Route::post('/inventory/purchase',           [WarehouseController::class, 'purchase']);
    Route::post('/inventory/upsert-pricing',     [WarehouseController::class, 'upsertPricing']);
    Route::put('/inventory/{inventory}',         [WarehouseController::class, 'updateInventory']);

    // ─── Expenses ──────────────────────────────────────────────
    Route::get('/expenses',              [ExpenseController::class, 'index']);
    Route::post('/expenses',             [ExpenseController::class, 'store']);
    Route::put('/expenses/{expense}',    [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);

    // ─── Reports & Dashboard ───────────────────────────────────
    Route::get('/reports/dashboard',    [ReportController::class, 'dashboardStats']);
    Route::get('/reports/monthly-trends', [ReportController::class, 'monthlyTrends']);
    Route::get('/reports/all-branches', [ReportController::class, 'allBranchBalance']);
    Route::get('/reports/top-books',    [ReportController::class, 'topBooks']);
    Route::get('/reports/notifications',[ReportController::class, 'notifications']);
    Route::get('/reports/iraq-profit',  [ReportController::class, 'iraqProfit']);
    Route::get('/reports/distribution-from-qom', [ReportController::class, 'distributionFromQom']);
    Route::get('/settings',             [ReportController::class, 'settings']);
    Route::put('/settings',             [ReportController::class, 'updateSettings']);

    // ─── Book categories ───────────────────────────────────────
    Route::get('/book-categories',                    [BookCategoryController::class, 'index']);
    Route::post('/book-categories',                   [BookCategoryController::class, 'store']);
    Route::put('/book-categories/rename',             [BookCategoryController::class, 'rename']);
    Route::delete('/book-categories/{category}',      [BookCategoryController::class, 'destroy'])->where('category', '.*');

    // ─── Users (admin) ─────────────────────────────────────────
    Route::get('/users',              [UserController::class, 'index']);
    Route::post('/users',             [UserController::class, 'store']);
    Route::put('/users/{user}',       [UserController::class, 'update']);
    Route::delete('/users/{user}',    [UserController::class, 'destroy']);
});