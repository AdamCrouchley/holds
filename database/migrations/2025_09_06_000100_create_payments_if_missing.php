<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();

                // Foreign keys (nullable so we can backfill)
                $table->foreignId('booking_id')->nullable()->index();
                $table->foreignId('job_id')->nullable()->index();
                $table->foreignId('customer_id')->nullable()->index();

                // Business identifiers
                $table->string('reference', 120)->nullable()->index(); // shared ref (e.g. ZT1756...)
                
                // Money (cents)
                $table->integer('amount_cents')->default(0);
                $table->string('currency', 10)->default('NZD');

                // Status & classification
                $table->string('status', 50)->default('pending')->index();  // pending|succeeded|failed|canceled
                $table->string('type', 50)->nullable()->index();            // booking_deposit|balance|post_hire|bond_hold|bond_capture|refund
                $table->string('purpose', 120)->nullable()->index();        // optional free-form
                $table->string('mechanism', 50)->nullable()->index();       // card|bank_transfer|cash|...

                // Stripe / PSP refs (nullable)
                $table->string('stripe_payment_intent_id', 191)->nullable()->index();
                $table->string('stripe_payment_method_id', 191)->nullable()->index();
                $table->string('stripe_charge_id', 191)->nullable()->index();

                // Extra
                $table->json('details')->nullable();

                $table->timestamps();

                // Helpful composite (kept short to avoid old MySQL key limits)
                $table->index(['reference', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Do NOT drop in down() to avoid nuking prod data by mistake
        // If you really want to allow rollback, uncomment below:
        // if (Schema::hasTable('payments')) Schema::drop('payments');
    }
};
