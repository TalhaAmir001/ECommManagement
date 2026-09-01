<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * carrier_name and tracking_url are populated from Shopify's
     * trackingInfo (and from real courier APIs in phase 2). Both are
     * optional so existing rows stay valid.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('carrier_name', 64)->nullable()->after('tracking_number');
            $table->string('tracking_url')->nullable()->after('carrier_name');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['carrier_name', 'tracking_url']);
        });
    }
};
