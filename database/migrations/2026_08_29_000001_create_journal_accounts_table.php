<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // asset | liability | equity | income | expense
            $table->string('type', 16);
            // Mark accounts that can be used as the "cash side" of an entry
            // (e.g. Cash, Bank). Used by the friendly form to pick where the
            // money came from / went to.
            $table->boolean('is_payment')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('color', 16)->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->index(['type', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_accounts');
    }
};
