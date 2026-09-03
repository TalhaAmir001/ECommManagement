<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product weights are the basis for shipment weight: a shipment linked to an
 * order inherits the order's total weight (quantity × product weight), which
 * the DeliveryRateCalculator then prices against the provider's zone/weight
 * rate card.
 *
 * Weight is stored in kilograms — the same unit as shipments.weight_kg and
 * the courier rate bands — regardless of the unit Shopify exposes (the sync
 * layer converts GRAMS / POUNDS / OUNCES to kg).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('weight_kg', 8, 3)->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('weight_kg');
        });
    }
};
