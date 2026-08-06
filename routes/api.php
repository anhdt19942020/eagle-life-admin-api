<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\SalesGroupController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderImportController;
use App\Http\Controllers\Api\PrintifyProductController;
use App\Http\Controllers\Api\PrintifyShopController;
use App\Http\Controllers\Api\PrintifyOrderController;
use App\Http\Controllers\Api\PrintifyAccountController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.view');

    Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::put('/users/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::patch('/users/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])->middleware('permission:users.delete');
    Route::patch('/users/{id}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.update');

    Route::get('/sales-groups', [SalesGroupController::class, 'index'])->middleware('permission:sales-groups.view');
    Route::post('/sales-groups', [SalesGroupController::class, 'store'])->middleware('permission:sales-groups.create');
    Route::get('/sales-groups/{sales_group}', [SalesGroupController::class, 'show'])->middleware('permission:sales-groups.view');
    Route::put('/sales-groups/{sales_group}', [SalesGroupController::class, 'update'])->middleware('permission:sales-groups.update');
    Route::patch('/sales-groups/{sales_group}', [SalesGroupController::class, 'update'])->middleware('permission:sales-groups.update');
    Route::delete('/sales-groups/{sales_group}', [SalesGroupController::class, 'destroy'])->middleware('permission:sales-groups.delete');

    // Orders - Phase 5
    Route::post('/orders/{id}/restore', [OrderController::class, 'restore']);
    Route::apiResource('orders', OrderController::class)->except(['store']);

    // Import eBay - Phase 6
    Route::get('/orders/import/template', [OrderImportController::class, 'template']);
    Route::post('/orders/import', [OrderImportController::class, 'import'])->middleware('permission:orders.import');
    Route::post('/orders/import-csv', [OrderImportController::class, 'importCsv'])->middleware('permission:orders.import');

    // Printify accounts
    Route::get('/printify/accounts', [PrintifyAccountController::class, 'index'])->middleware('permission:printify.accounts.view');
    Route::post('/printify/accounts', [PrintifyAccountController::class, 'store'])->middleware('permission:printify.accounts.manage');
    Route::get('/printify/accounts/{account}', [PrintifyAccountController::class, 'show'])->middleware('permission:printify.accounts.view');
    Route::patch('/printify/accounts/{account}', [PrintifyAccountController::class, 'update'])->middleware('permission:printify.accounts.manage');
    Route::post('/printify/accounts/{account}/deactivate', [PrintifyAccountController::class, 'deactivate'])->middleware('permission:printify.accounts.manage');
    Route::post('/printify/accounts/{account}/activate', [PrintifyAccountController::class, 'activate'])->middleware('permission:printify.accounts.manage');

    // Printify catalog - Phase 7
    Route::get('/printify/shops', [PrintifyShopController::class, 'index'])->middleware('permission:printify.catalog.view');
    Route::post('/printify/shops/sync', [PrintifyShopController::class, 'sync'])->middleware('permission:printify.sync');
    Route::patch('/printify/shops/{shop}', [PrintifyShopController::class, 'updateDefaultSku'])->middleware('permission:printify.shop-readiness.confirm');
    Route::post('/printify/shops/{shop}/confirm-manual-approval', [PrintifyShopController::class, 'confirmManualApproval'])->middleware('permission:printify.shop-readiness.confirm');
    Route::post('/printify/shops/{shop}/open', [PrintifyShopController::class, 'open'])->middleware('permission:printify.shop-readiness.confirm');
    Route::post('/printify/shops/{shop}/close', [PrintifyShopController::class, 'close'])->middleware('permission:printify.shop-readiness.confirm');
    Route::get('/printify/products', [PrintifyProductController::class, 'index'])->middleware('permission:printify.catalog.view');
    Route::post('/orders/{order}/printify-preview', [PrintifyOrderController::class, 'preview'])->middleware('permission:printify.order.create');
    Route::post('/orders/{order}/printify-create', [PrintifyOrderController::class, 'create'])->middleware('permission:printify.order.create');
});
