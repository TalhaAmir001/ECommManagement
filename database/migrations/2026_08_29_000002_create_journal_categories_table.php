<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // expense | income
            $table->string('type', 16);
            // The P&L account this category defaults to. For an expense
            // category this is an expense account; for an income category
            // this is an income account. The double-entry form resolves
            // the corresponding line from this.
            $table->foreignId('default_account_id')
                ->constrained('journal_accounts')
                ->restrictOnDelete();
            $table->string('color', 16)->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->index(['type', 'archived']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_categories');
    }
};
