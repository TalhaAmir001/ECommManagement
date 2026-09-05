<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Store the phone Shopify reports on the order's shipping address so the
     * new-shipment form can pre-fill the consignee phone from the order that
     * is actually being delivered — not just the customer's default phone,
     * which is often blank. Nullable because not every order carries a
     * shipping-address phone.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipping_phone')->nullable()->after('shipping_country');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_phone');
        });
    }
};