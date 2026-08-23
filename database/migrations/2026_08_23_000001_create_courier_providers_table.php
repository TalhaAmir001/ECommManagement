<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * courier_providers is the runtime config table for every courier
     * integration in this app. Credentials are encrypted at rest, capabilities
     * are a JSON array of strings that map to the Capability enum, and the
     * poll cadence lets each provider declare its own preferred interval.
     */
    public function up(): void
    {
        Schema::create('courier_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('display_name');
            $table->string('driver_class');
            $table->boolean('enabled')->default(true);
            $table->text('credentials')->nullable();   // encrypted JSON
            $table->json('settings')->nullable();       // provider-specific knobs
            $table->json('capabilities')->nullable();   // list<Capability>
            $table->unsignedSmallInteger('poll_interval_minutes')->default(15);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status', 16)->nullable(); // success | failed
            $table->text('last_sync_error')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_providers');
    }
};
