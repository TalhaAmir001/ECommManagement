<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * shipment_events is the append-only tracking timeline for each shipment.
     * The unique index on (shipment_id, occurred_at, location) makes the
     * "append new events" operation idempotent: re-running the same sync
     * cannot duplicate a tracking event.
     */
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->timestamp('occurred_at');
            $table->string('status', 32);
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'occurred_at', 'location'], 'shipment_events_unique_event');
            $table->index(['shipment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
