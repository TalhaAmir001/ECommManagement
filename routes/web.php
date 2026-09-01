<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\CourierProviderController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JournalCategoryController;
use App\Http\Controllers\JournalEntryController;
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

// Journal entries — manual expenses / income that adjust the P&L.
Route::get('/journal', [JournalEntryController::class, 'index'])->name('journal.index');
Route::get('/journal/create', [JournalEntryController::class, 'create'])->name('journal.create');
Route::post('/journal', [JournalEntryController::class, 'store'])->name('journal.store');
Route::get('/journal/categories', [JournalCategoryController::class, 'index'])->name('journal.categories');
Route::post('/journal/categories', [JournalCategoryController::class, 'store'])->name('journal.categories.store');
Route::get('/journal/{entry}', [JournalEntryController::class, 'show'])->name('journal.show')->whereNumber('entry');
Route::get('/journal/{entry}/edit', [JournalEntryController::class, 'edit'])->name('journal.edit')->whereNumber('entry');
Route::put('/journal/{entry}', [JournalEntryController::class, 'update'])->name('journal.update')->whereNumber('entry');
Route::delete('/journal/{entry}', [JournalEntryController::class, 'destroy'])->name('journal.destroy')->whereNumber('entry');

// Manage journal categories (CRUD).
Route::put('/journal/categories/{category}', [JournalCategoryController::class, 'update'])->name('journal.categories.update')->whereNumber('category');
Route::delete('/journal/categories/{category}', [JournalCategoryController::class, 'destroy'])->name('journal.categories.destroy')->whereNumber('category');

// Shipments / couriers
Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
Route::post('/shipments', [ShipmentController::class, 'store'])->name('shipments.store');
Route::get('/shipments/lookup-orders', [ShipmentController::class, 'lookupOrders'])->name('shipments.lookup-orders');
Route::get('/shipments/generate-tracking-number', [ShipmentController::class, 'generateTrackingNumber'])->name('shipments.generate-tracking-number');
Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
Route::post('/shipments/{shipment}/link', [ShipmentController::class, 'link'])->name('shipments.link');
Route::post('/shipments/{shipment}/unlink', [ShipmentController::class, 'unlink'])->name('shipments.unlink');
Route::post('/shipments/{shipment}/rematch', [ShipmentController::class, 'rematch'])->name('shipments.rematch');
Route::post('/shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->name('shipments.refresh');
Route::post('/shipments/{shipment}/events', [ShipmentController::class, 'addEvent'])->name('shipments.events.store');

// Order-level quick actions: add tracking, assign courier.
Route::post('/orders/{order}/add-tracking', [OrderController::class, 'addTrackingNumber'])->name('orders.add-tracking');
Route::post('/orders/{order}/assign-provider', [OrderController::class, 'assignProvider'])->name('orders.assign-provider');

// Courier settings — add and configure courier service provider APIs.
Route::get('/couriers/settings', [CourierProviderController::class, 'index'])->name('couriers.settings');
Route::get('/couriers/settings/create', [CourierProviderController::class, 'create'])->name('couriers.create');
Route::post('/couriers/settings', [CourierProviderController::class, 'store'])->name('couriers.store');
Route::get('/couriers/settings/{provider}', [CourierProviderController::class, 'edit'])->name('couriers.edit');
Route::put('/couriers/settings/{provider}', [CourierProviderController::class, 'update'])->name('couriers.update');
Route::delete('/couriers/settings/{provider}', [CourierProviderController::class, 'destroy'])->name('couriers.destroy');

Route::post('/courier-providers/{provider}/toggle', [CourierProviderController::class, 'toggle'])->name('courier-providers.toggle');
Route::post('/courier-providers/{provider}/sync', [CourierProviderController::class, 'syncNow'])->name('courier-providers.sync');

Route::post('/webhooks/shopify/{topic}', [ShopifyWebhookController::class, 'handle'])->where('topic', '.*');
