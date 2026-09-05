<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Shipments carry consignee contact details for the courier. Email was
     * missing from the original set (name/phone/city/address); it lets the
     * new-shipment form pre-fill the consignee email from the linked order's
     * customer record.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('consignee_email')->nullable()->after('consignee_city');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn('consignee_email');
        });
    }
};
