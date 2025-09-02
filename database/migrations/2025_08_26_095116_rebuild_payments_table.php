<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Dev convenience: drop the old minimal table
        Schema::dropIfExists('payments');

        Schema::create('payments', function (Blueprint $t) {
            $t->id();

            // relations (not adding FKs in sqlite here)
            $t->unsignedBigInteger('booking_id');
            $t->unsignedBigInteger('customer_id');

            // what kind of payment this is
            $t->enum('type', ['booking_deposit','balance','extra','refund'])->index();

            // money
            $t->integer('amount');           // cents
            $t->string('currency', 3)->default('NZD');

            // Stripe plumbing
            $t->string('stripe_payment_intent_id')->nullable()->index();
            $t->string('stripe_charge_id')->nullable()->index();

            // lifecycle
            $t->enum('status', ['pending','succeeded','failed','canceled'])->default('pending')->index();

            // misc
            $t->json('details')->nullable();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
