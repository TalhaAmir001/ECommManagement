<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Store the shipping address Shopify reports for each order so the
     * new-shipment form (and fulfillment ingest) can pre-fill the consignee
     * details instead of leaving city/address blank. Every column is nullable
     * because not every order carries a shipping address.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_name')->nullable()->after('fulfillment_status');
            $table->string('shipping_address1')->nullable()->after('shipping_name');
            $table->string('shipping_address2')->nullable()->after('shipping_address1');
            $table->string('shipping_city')->nullable()->after('shipping_address2');
            $table->string('shipping_province')->nullable()->after('shipping_city');
            $table->string('shipping_zip')->nullable()->after('shipping_province');
            $table->string('shipping_country')->nullable()->after('shipping_zip');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_name',
                'shipping_address1',
                'shipping_address2',
                'shipping_city',
                'shipping_province',
                'shipping_zip',
                'shipping_country',
            ]);
        });
    }
};
