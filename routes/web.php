<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\CourierProviderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\Webhook\ShopifyWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::get('/orders/updates', [OrderController::class, 'updates'])->name('orders.updates');
Route::get('/orders/rows', [OrderController::class, 'rows'])->name('orders.rows');
Route::get('/reports', [AuditController::class, 'index'])->name('audit.index');

// Shipments / couriers
Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
Route::post('/shipments', [ShipmentController::class, 'store'])->name('shipments.store');
Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
Route::post('/shipments/{shipment}/link', [ShipmentController::class, 'link'])->name('shipments.link');
Route::post('/shipments/{shipment}/unlink', [ShipmentController::class, 'unlink'])->name('shipments.unlink');
Route::post('/shipments/{shipment}/rematch', [ShipmentController::class, 'rematch'])->name('shipments.rematch');
Route::post('/shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->name('shipments.refresh');
Route::post('/shipments/{shipment}/events', [ShipmentController::class, 'addEvent'])->name('shipments.events.store');

Route::post('/courier-providers/{provider}/toggle', [CourierProviderController::class, 'toggle'])->name('courier-providers.toggle');
Route::post('/courier-providers/{provider}/sync', [CourierProviderController::class, 'syncNow'])->name('courier-providers.sync');

Route::post('/webhooks/shopify/{topic}', [ShopifyWebhookController::class, 'handle'])->where('topic', '.*');
