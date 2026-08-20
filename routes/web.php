<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\Webhook\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/updates', [OrderController::class, 'updates'])->name('orders.updates');
Route::get('/orders/rows', [OrderController::class, 'rows'])->name('orders.rows');

Route::post('/webhooks/shopify/{topic}', [ShopifyWebhookController::class, 'handle'])->where('topic', '.*');
