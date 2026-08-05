<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderImportController;
use App\Http\Controllers\Api\PrintifyProductController;
use App\Http\Controllers\Api\PrintifyShopController;
use App\Http\Controllers\Api\PrintifyOrderController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Roles
    Route::get('/roles', [RoleController::class, 'index']);

    // Users
    Route::apiResource('users', UserController::class);
    Route::patch('/users/{id}/status', [UserController::class, 'updateStatus']);

    // Orders - Phase 5
    Route::apiResource('orders', OrderController::class)->except(['store']);

    // Import eBay - Phase 6
    Route::get('/orders/import/template', [OrderImportController::class, 'template']);
    Route::post('/orders/import', [OrderImportController::class, 'import'])->middleware('permission:orders.import');
    Route::post('/orders/import-csv', [OrderImportController::class, 'importCsv'])->middleware('permission:orders.import');

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
