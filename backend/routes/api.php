<?php

use App\Http\Controllers\Api\V1\GroceryController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok', 'product' => 'grocery-erp', 'version' => '1.0.0']));
Route::post('/v1/login', [App\Http\Controllers\Api\V1\Auth\AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/v1/logout', [App\Http\Controllers\Api\V1\Auth\AuthController::class, 'logout']);
    Route::get('/v1/user', [App\Http\Controllers\Api\V1\Auth\AuthController::class, 'user']);

    Route::get('/v1/grocery/options', [GroceryController::class, 'options']);
    Route::get('/v1/grocery/dashboard', [GroceryController::class, 'dashboard'])->middleware('role.permission:dashboard');

    Route::get('/v1/grocery/products', [GroceryController::class, 'products'])->middleware('role.permission:products');
    Route::post('/v1/grocery/products', [GroceryController::class, 'storeProduct'])->middleware('role.permission:products,can_create');
    Route::put('/v1/grocery/products/{id}', [GroceryController::class, 'updateProduct'])->middleware('role.permission:products,can_update');
    Route::delete('/v1/grocery/products/{id}', [GroceryController::class, 'destroyProduct'])->middleware('role.permission:products,can_delete');

    Route::get('/v1/grocery/masters/{resource}', [GroceryController::class, 'masterIndex']);
    Route::post('/v1/grocery/masters/{resource}', [GroceryController::class, 'masterStore']);
    Route::put('/v1/grocery/masters/{resource}/{id}', [GroceryController::class, 'masterUpdate']);
    Route::delete('/v1/grocery/masters/{resource}/{id}', [GroceryController::class, 'masterDestroy']);

    Route::get('/v1/grocery/shifts', [GroceryController::class, 'shifts'])->middleware('role.permission:shifts');
    Route::post('/v1/grocery/shifts/open', [GroceryController::class, 'openShift'])->middleware('role.permission:shifts,can_create');
    Route::post('/v1/grocery/shifts/{id}/close', [GroceryController::class, 'closeShift'])->middleware('role.permission:shifts,can_update');
    Route::post('/v1/grocery/cash-movements', [GroceryController::class, 'cashMovement'])->middleware('role.permission:cash,can_create');

    Route::get('/v1/grocery/sales', [GroceryController::class, 'sales'])->middleware('role.permission:sales');
    Route::get('/v1/grocery/sales/{id}', [GroceryController::class, 'sale'])->middleware('role.permission:sales');
    Route::post('/v1/grocery/sales/{id}/print', [GroceryController::class, 'printSale'])->middleware('role.permission:sales');
    Route::post('/v1/grocery/sales/{id}/void', [GroceryController::class, 'voidSale'])->middleware('role.permission:sales,can_update');
    Route::post('/v1/grocery/pos/complete', [GroceryController::class, 'completeSale'])->middleware('role.permission:pos,can_create');
    Route::post('/v1/grocery/pos/hold', [GroceryController::class, 'holdSale'])->middleware('role.permission:pos,can_create');
    Route::post('/v1/grocery/sales-returns', [GroceryController::class, 'salesReturn'])->middleware('role.permission:sales-returns,can_create');

    Route::get('/v1/grocery/inventory', [GroceryController::class, 'inventory'])->middleware('role.permission:inventory');
    Route::post('/v1/grocery/stock-adjustments', [GroceryController::class, 'adjustStock'])->middleware('role.permission:adjustments,can_create');
    Route::get('/v1/grocery/transfers', [GroceryController::class, 'transfers'])->middleware('role.permission:transfers');
    Route::post('/v1/grocery/transfers', [GroceryController::class, 'transferStock'])->middleware('role.permission:transfers,can_create');
    Route::post('/v1/grocery/transfers/{id}/receive', [GroceryController::class, 'receiveTransfer'])->middleware('role.permission:transfers,can_update');
    Route::get('/v1/grocery/stock-counts', [GroceryController::class, 'stockCounts'])->middleware('role.permission:stock-counts');
    Route::get('/v1/grocery/stock-counts/{id}', [GroceryController::class, 'stockCount'])->middleware('role.permission:stock-counts');
    Route::post('/v1/grocery/stock-counts', [GroceryController::class, 'createStockCount'])->middleware('role.permission:stock-counts,can_create');
    Route::post('/v1/grocery/stock-counts/{id}/post', [GroceryController::class, 'postStockCount'])->middleware('role.permission:stock-counts,can_update');

    Route::get('/v1/grocery/purchase-orders', [GroceryController::class, 'purchaseOrders'])->middleware('role.permission:purchases');
    Route::post('/v1/grocery/purchase-orders', [GroceryController::class, 'storePurchaseOrder'])->middleware('role.permission:purchases,can_create');
    Route::post('/v1/grocery/purchase-orders/{id}/approve', [GroceryController::class, 'approvePurchaseOrder'])->middleware('role.permission:purchases,can_update');
    Route::get('/v1/grocery/goods-receipts', [GroceryController::class, 'receipts'])->middleware('role.permission:purchases');
    Route::post('/v1/grocery/goods-receipts', [GroceryController::class, 'receiveGoods'])->middleware('role.permission:purchases,can_create');
    Route::post('/v1/grocery/purchase-returns', [GroceryController::class, 'purchaseReturn'])->middleware('role.permission:purchase-returns,can_create');
    Route::post('/v1/grocery/supplier-payments', [GroceryController::class, 'supplierPayment'])->middleware('role.permission:supplier-payments,can_create');
    Route::get('/v1/grocery/cheques', [GroceryController::class, 'cheques'])->middleware('role.permission:accounts');
    Route::patch('/v1/grocery/cheques/{id}', [GroceryController::class, 'updateCheque'])->middleware('role.permission:accounts,can_update');
    Route::get('/v1/grocery/customer-accounts', [GroceryController::class, 'customerAccounts'])->middleware('role.permission:customers');
    Route::post('/v1/grocery/customer-payments', [GroceryController::class, 'customerPayment'])->middleware('role.permission:customers,can_create');

    Route::get('/v1/grocery/expenses', [GroceryController::class, 'expenses'])->middleware('role.permission:expenses');
    Route::post('/v1/grocery/expenses', [GroceryController::class, 'storeExpense'])->middleware('role.permission:expenses,can_create');

    Route::get('/v1/grocery/audit', [GroceryController::class, 'auditLog'])->middleware('role.permission:audit');
    Route::get('/v1/grocery/reports/{report}', [GroceryController::class, 'report'])->middleware('role.permission:reports');

    // Reused grocery-relevant master registries.
    Route::apiResource('/v1/branches', App\Http\Controllers\Api\V1\BranchController::class)->middleware('role.permission:settings');
    Route::apiResource('/v1/companies', App\Http\Controllers\Api\V1\CompanyController::class)->middleware('role.permission:settings');
    Route::apiResource('/v1/stores', App\Http\Controllers\Api\V1\StoreController::class)->middleware('role.permission:stores');
    Route::apiResource('/v1/categories', App\Http\Controllers\Api\V1\CategoryController::class)->middleware('role.permission:categories');
    Route::apiResource('/v1/brands', App\Http\Controllers\Api\V1\BrandController::class)->middleware('role.permission:brands');
    Route::apiResource('/v1/customers', App\Http\Controllers\Api\V1\CustomerController::class)->middleware('role.permission:customers');
    Route::apiResource('/v1/suppliers', App\Http\Controllers\Api\V1\SupplierController::class)->middleware('role.permission:suppliers');
    Route::apiResource('/v1/roles', App\Http\Controllers\Api\V1\RoleController::class)->middleware('super.admin');
    Route::apiResource('/v1/users', App\Http\Controllers\Api\V1\UserController::class)->middleware('super.admin');
    Route::get('/v1/permissions', [App\Http\Controllers\Api\V1\PermissionController::class, 'index'])->middleware('super.admin');
    Route::put('/v1/permissions/{role}', [App\Http\Controllers\Api\V1\PermissionController::class, 'update'])->middleware('super.admin');
    Route::get('/v1/system/backups', [App\Http\Controllers\Api\V1\SystemBackupController::class, 'index'])->middleware('super.admin');
    Route::post('/v1/system/backups', [App\Http\Controllers\Api\V1\SystemBackupController::class, 'store'])->middleware('super.admin');
    Route::get('/v1/system/backups/{backup}/download', [App\Http\Controllers\Api\V1\SystemBackupController::class, 'download'])->middleware('super.admin');
    Route::post('/v1/system/backups/{backup}/restore', [App\Http\Controllers\Api\V1\SystemBackupController::class, 'restore'])->middleware('super.admin');
});
