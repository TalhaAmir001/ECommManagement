<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * shipments is the provider-agnostic source of truth for every parcel this
     * store has ever handed off to a courier. (courier_provider_id, external_id)
     * is the natural key — a single shipment must not exist twice in the local
     * DB, even if the provider's API returns it on two polls.
     */
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('courier_provider_id')->constrained('courier_providers')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('tracking_number')->index();
            $table->string('reference')->nullable();         // provider-side ref, often = orders.number
            $table->string('status', 32);                     // ShipmentStatus enum value
            $table->text('status_detail')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_event_at')->nullable();

            $table->string('consignor_name')->nullable();
            $table->string('consignor_phone')->nullable();
            $table->string('consignor_address')->nullable();
            $table->string('consignor_city')->nullable();

            $table->string('consignee_name')->nullable();
            $table->string('consignee_phone')->nullable();
            $table->string('consignee_address')->nullable();
            $table->string('consignee_city')->nullable();

            $table->decimal('weight_kg', 8, 3)->nullable();
            $table->unsignedSmallInteger('pieces')->nullable();

            $table->decimal('cod_amount', 12, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('currency', 3)->default('PKR');

            $table->json('raw_payload')->nullable();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->string('matched_method', 32)->nullable();  // phone | reference | manual

            $table->timestamps();

            $table->unique(['courier_provider_id', 'external_id'], 'shipments_provider_external_unique');
            $table->index('order_id');
            $table->index('status');
            $table->index('last_event_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
