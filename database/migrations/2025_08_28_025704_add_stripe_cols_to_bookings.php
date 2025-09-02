<?php

// database/migrations/2025_08_30_000001_add_stripe_cols_to_bookings.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('bookings', function (Blueprint $t) {
            $t->string('portal_token', 64)->nullable()->index(); // if not present
            $t->string('stripe_balance_pi_id', 255)->nullable(); // PaymentIntent for balance
            $t->string('stripe_bond_pi_id', 255)->nullable();    // PaymentIntent for bond hold
            $t->timestamp('bond_authorized_at')->nullable();
            $t->timestamp('bond_captured_at')->nullable();
            $t->timestamp('last_payment_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropColumn([
                'portal_token','stripe_balance_pi_id','stripe_bond_pi_id',
                'bond_authorized_at','bond_captured_at','last_payment_at'
            ]);
        });
    }
};
