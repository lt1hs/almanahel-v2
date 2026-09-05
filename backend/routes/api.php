<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchCatalogController;
use App\Http\Controllers\Api\BookController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SupplierAccountController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ConsignmentController;
use App\Http\Controllers\Api\ReturnController;
use App\Http\Controllers\Api\GiftController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\BookCategoryController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\FinanceReportController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuditMutations;

// Public Routes
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Protected Routes
Route::middleware(['auth:sanctum', AuditMutations::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user',    [AuthController::class, 'user']);

    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/customers/{customer}', [CustomerController::class, 'show']);
    Route::put('/customers/{customer}', [CustomerController::class, 'update']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    Route::post('/customers/{customer}/payments', [CustomerController::class, 'pay']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/dismiss-all', [NotificationController::class, 'dismissAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss']);

    Route::get('/branch-catalog', [BranchCatalogController::class, 'index']);

    // ─── Books ─────────────────────────────────────────────────
    Route::get('/books',               [BookController::class, 'index']);
    Route::post('/books',              [BookController::class, 'store']);
    Route::post('/books/upload-cover', [BookController::class, 'uploadCover']);
    Route::get('/books/low-stock',     [BookController::class, 'lowStock']);
    Route::get('/books/by-barcode/{code}', [BookController::class, 'byBarcode']);
    Route::get('/books/{book}',        [BookController::class, 'show']);
    Route::put('/books/{book}',        [BookController::class, 'update']);
    Route::delete('/books/{book}',     [BookController::class, 'destroy'])->middleware('role:admin');
    Route::get('/branches/{branchId}/books', [BookController::class, 'byBranch']);

    // ─── Branches ──────────────────────────────────────────────
    Route::get('/branches',              [BranchController::class, 'index']);
    Route::get('/branches/{branch}',     [BranchController::class, 'show']);
    Route::get('/branches/{branch}/profit', [BranchController::class, 'profit']);
    Route::post('/branches',             [BranchController::class, 'store'])->middleware('role:admin');
    Route::put('/branches/{branch}',     [BranchController::class, 'update'])->middleware('role:admin');
    Route::delete('/branches/{branch}',  [BranchController::class, 'destroy'])->middleware('role:admin');

    // ─── Suppliers ─────────────────────────────────────────────
    Route::get('/suppliers',               [SupplierController::class, 'index'])->middleware('role:admin');
    Route::post('/suppliers',              [SupplierController::class, 'store'])->middleware('role:admin');
    Route::get('/suppliers/{supplier}',    [SupplierController::class, 'show'])->middleware('role:admin');
    Route::put('/suppliers/{supplier}',    [SupplierController::class, 'update'])->middleware('role:admin');
    Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('role:admin');
    Route::get('/suppliers/{supplier}/balance', [SupplierController::class, 'balance'])->middleware('role:admin');

    Route::get('/supplier-accounts', [SupplierAccountController::class, 'index']);
    Route::post('/supplier-accounts', [SupplierAccountController::class, 'store']);
    Route::get('/supplier-accounts/{supplierAccount}', [SupplierAccountController::class, 'show']);
    Route::put('/supplier-accounts/{supplierAccount}', [SupplierAccountController::class, 'update']);
    Route::get('/supplier-accounts/{supplierAccount}/balance', [SupplierAccountController::class, 'balance']);

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
    Route::get('/consignments/settlements/{settlement}', [ConsignmentController::class, 'showSettlement']);
    Route::post('/consignments/settle',            [ConsignmentController::class, 'settle']);
    Route::post('/consignments/settle-bulk',       [ConsignmentController::class, 'settleBulk']);
    Route::put('/consignments/settlements/{settlement}/check', [ConsignmentController::class, 'updateSettlementCheck']);
    Route::post('/consignments/{consignmentReceipt}/close', [ConsignmentController::class, 'close']);
    Route::get('/consignments/{consignmentReceipt}', [ConsignmentController::class, 'show']);

    // ─── Returns ───────────────────────────────────────────────
    Route::get('/returns/customer',  [ReturnController::class, 'customerReturns']);
    Route::post('/returns/customer', [ReturnController::class, 'createCustomerReturn']);
    Route::get('/returns/consignment/eligible', [ReturnController::class, 'eligibleConsignmentStock']);
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
    Route::get('/expenses',              [ExpenseController::class, 'index'])->middleware('role:accountant,branch_manager');
    Route::post('/expenses',             [ExpenseController::class, 'store'])->middleware('role:accountant,branch_manager');
    Route::put('/expenses/{expense}',    [ExpenseController::class, 'update'])->middleware('role:accountant,branch_manager');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('role:accountant,branch_manager');

    // ─── Reports & Dashboard ───────────────────────────────────
    Route::get('/reports/dashboard',    [ReportController::class, 'dashboardStats']);
    Route::get('/reports/monthly-trends', [ReportController::class, 'monthlyTrends']);
    Route::get('/reports/all-branches', [ReportController::class, 'allBranchBalance'])->middleware('role:reports');
    Route::get('/reports/top-books',    [ReportController::class, 'topBooks']);
    Route::get('/reports/notifications',[ReportController::class, 'notifications']);
    Route::get('/reports/iraq-profit',  [ReportController::class, 'iraqProfit'])->middleware('role:reports');
    Route::get('/finance/pnl', [FinanceReportController::class, 'pnl']);
    Route::get('/finance/treasury', [FinanceReportController::class, 'treasury']);
    Route::get('/finance/trial-balance', [FinanceReportController::class, 'trialBalance']);
    Route::get('/finance/financial-position', [FinanceReportController::class, 'financialPosition']);
    Route::get('/finance/receivables', [FinanceReportController::class, 'receivables']);
    Route::get('/finance/payables', [FinanceReportController::class, 'payables']);
    Route::get('/finance/checks', [FinanceReportController::class, 'checks']);
    Route::get('/finance/inventory-value', [FinanceReportController::class, 'inventoryValue']);
    Route::get('/reports/distribution-from-qom', [ReportController::class, 'distributionFromQom'])->middleware('role:reports');
    Route::get('/settings',             [ReportController::class, 'settings']);
    Route::put('/settings',             [ReportController::class, 'updateSettings'])->middleware('role:admin');

    // ─── Book categories ───────────────────────────────────────
    Route::get('/book-categories',                    [BookCategoryController::class, 'index']);
    Route::post('/book-categories',                   [BookCategoryController::class, 'store'])->middleware('role:admin');
    Route::put('/book-categories/rename',             [BookCategoryController::class, 'rename'])->middleware('role:admin');
    Route::delete('/book-categories/{category}',      [BookCategoryController::class, 'destroy'])->middleware('role:admin')->where('category', '.*');

    // ─── Users & activity (admin) ──────────────────────────────
    Route::middleware('role:admin')->group(function () {
        Route::get('/users',              [UserController::class, 'index']);
        Route::post('/users',             [UserController::class, 'store']);
        Route::put('/users/{user}',       [UserController::class, 'update']);
        Route::delete('/users/{user}',    [UserController::class, 'destroy']);

        Route::get('/activity-logs',              [ActivityLogController::class, 'index']);
        Route::get('/activity-logs/meta',         [ActivityLogController::class, 'meta']);
        Route::get('/activity-logs/summary',      [ActivityLogController::class, 'summary']);
        Route::get('/activity-logs/export',       [ActivityLogController::class, 'export']);
        Route::get('/activity-logs/{activityLog}', [ActivityLogController::class, 'show']);
    });
});
