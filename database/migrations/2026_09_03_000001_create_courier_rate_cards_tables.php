<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery rate cards per courier provider.
 *
 * Each provider can define named zones (collections of cities) and a rate
 * matrix of origin_zone × destination_zone × weight range → price. The
 * DeliveryRateCalculator resolves a shipment's origin/destination zone from
 * its consignor/consignee city and picks the matching weight band, giving an
 * estimated courier cost for shipments whose actual cost was never recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_provider_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('cities');                      // list<string> normalized (lowercase)
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['courier_provider_id', 'name'], 'courier_zones_provider_name_unique');
        });

        Schema::create('courier_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_zone_id')->constrained('courier_zones')->cascadeOnDelete();
            $table->foreignId('destination_zone_id')->constrained('courier_zones')->cascadeOnDelete();
            $table->decimal('weight_from_kg', 8, 3)->default(0);
            $table->decimal('weight_to_kg', 8, 3)->nullable(); // null = no upper bound
            $table->decimal('price', 12, 2);
            $table->decimal('cod_fee', 12, 2)->nullable();     // optional surcharge for COD parcels
            $table->string('currency', 3)->default('PKR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['courier_provider_id', 'origin_zone_id', 'destination_zone_id', 'weight_from_kg'],
                'courier_rates_cell_unique'
            );
            $table->index(['courier_provider_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_rates');
        Schema::dropIfExists('courier_zones');
    }
};
