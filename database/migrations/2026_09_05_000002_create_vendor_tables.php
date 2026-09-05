<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendors and the goods/money that flow between them.
 *
 * A vendor supplies products or raw materials that we purchase. Each
 * purchase adds to what we owe (accounts payable), and each payment to the
 * vendor reduces it. The running balance is derived on the fly:
 *
 *     balance = Σ vendor_purchases.total_cost − Σ vendor_payments.amount
 *
 * A positive balance means the vendor is owed money; a negative one means
 * they are in credit (overpaid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('currency', 3)->default('PKR');
            $table->timestamps();
        });

        Schema::create('vendor_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 100)->nullable();        // invoice / PO number
            $table->string('item_description');                  // goods or raw material
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 20)->nullable();              // kg, pcs, roll, meter…
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_cost', 12, 2);                // snapshot = qty × unit cost
            $table->date('purchase_date');
            $table->string('currency', 3)->default('PKR');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'purchase_date']);
        });

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('payment_date');
            $table->string('method', 100)->nullable();           // Cash, Bank Transfer…
            $table->string('reference', 100)->nullable();        // bank / cheque / txn ref
            $table->text('notes')->nullable();
            $table->string('currency', 3)->default('PKR');
            $table->timestamps();

            $table->index(['vendor_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('vendor_purchases');
        Schema::dropIfExists('vendors');
    }
};
