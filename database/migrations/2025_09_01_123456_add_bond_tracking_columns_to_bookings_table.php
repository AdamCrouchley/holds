<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            if (!Schema::hasColumn('bookings','stripe_bond_pi_id')) {
                $t->string('stripe_bond_pi_id')->nullable()->index();
            }
            if (!Schema::hasColumn('bookings','bond_authorized_at')) {
                $t->timestamp('bond_authorized_at')->nullable();
            }
            if (!Schema::hasColumn('bookings','bond_captured_at')) {
                $t->timestamp('bond_captured_at')->nullable();
            }
            if (!Schema::hasColumn('bookings','bond_released_at')) {
                $t->timestamp('bond_released_at')->nullable();
            }
            if (!Schema::hasColumn('bookings','hold_amount')) {
                // amount to pre-auth in integer cents
                $t->integer('hold_amount')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            if (Schema::hasColumn('bookings','bond_released_at')) $t->dropColumn('bond_released_at');
            if (Schema::hasColumn('bookings','bond_captured_at')) $t->dropColumn('bond_captured_at');
            if (Schema::hasColumn('bookings','bond_authorized_at')) $t->dropColumn('bond_authorized_at');
            if (Schema::hasColumn('bookings','stripe_bond_pi_id')) $t->dropColumn('stripe_bond_pi_id');
            // don’t drop hold_amount if you’re already using it—only if you added it here:
            // if (Schema::hasColumn('bookings','hold_amount')) $t->dropColumn('hold_amount');
        });
    }
};
