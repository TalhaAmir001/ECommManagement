<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('shopify_id')->nullable()->unique();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
            $table->string('shopify_id')->nullable()->unique();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('shopify_id')->nullable()->unique();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('shopify_id')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropUnique(['order_items_shopify_id_unique']);
            $table->dropColumn('shopify_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['orders_shopify_id_unique']);
            $table->dropColumn('shopify_id');
            $table->foreignId('customer_id')->nullable(false)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['products_shopify_id_unique']);
            $table->dropColumn('shopify_id');
            $table->string('sku')->nullable(false)->change();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['customers_shopify_id_unique']);
            $table->dropColumn('shopify_id');
            $table->string('email')->nullable(false)->change();
        });
    }
};
