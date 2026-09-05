<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping fee the store CHARGES the customer on manual shipments.
 *
 * This is income the owner receives (unlike `shipments.cost`, which is what
 * we pay the courier). The P&L report adds it as the
 * "Shipping received (manual shipments only)" line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->decimal('shipping_charged', 12, 2)->nullable()->after('cod_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('shipping_charged');
        });
    }
};
