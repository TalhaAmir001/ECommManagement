<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            // Human-readable reference, e.g. "JE-000123".
            $table->string('reference', 32)->unique();
            // Optional category tag (e.g. "Shipping", "Marketing").
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('journal_categories')
                ->nullOnDelete();
            $table->string('description')->nullable();
            // draft | posted. Only posted entries hit the P&L.
            $table->string('status', 16)->default('posted');
            $table->timestamps();

            $table->index(['entry_date', 'status']);
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
