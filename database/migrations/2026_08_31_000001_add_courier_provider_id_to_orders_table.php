<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "early binding" between an order and the courier that will move
     * it. Until now an order had no opinion about who would ship it — a
     * shipment just linked back to the order after the fact via the
     * auto-matcher. This column lets the operations team declare the
     * courier up front, so:
     *
     *  - the order page can show "Will ship via Leopards" instead of
     *    "Not shipped" while we wait for the fulfillment,
     *  - the typeahead/auto-link logic can prefer matching carriers when
     *    a shipment comes in,
     *  - the future "Book shipment" button can use this as the default
     *    provider.
     *
     * Nullable + nullOnDelete so removing a courier provider doesn't
     * take historical orders with it.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('courier_provider_id')
                ->nullable()
                ->after('fulfillment_status')
                ->constrained('courier_providers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('courier_provider_id');
        });
    }
};
