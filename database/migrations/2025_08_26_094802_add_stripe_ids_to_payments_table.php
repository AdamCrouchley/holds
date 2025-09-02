<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            if (!Schema::hasColumn('payments', 'stripe_payment_intent_id')) {
                $t->string('stripe_payment_intent_id')->nullable()->index()->after('currency');
            }
            if (!Schema::hasColumn('payments', 'stripe_charge_id')) {
                $t->string('stripe_charge_id')->nullable()->index()->after('stripe_payment_intent_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            if (Schema::hasColumn('payments', 'stripe_payment_intent_id')) {
                $t->dropColumn('stripe_payment_intent_id');
            }
            if (Schema::hasColumn('payments', 'stripe_charge_id')) {
                $t->dropColumn('stripe_charge_id');
            }
        });
    }
};
