<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            if (!Schema::hasColumn('customers','stripe_customer_id')) {
                $t->string('stripe_customer_id')->nullable()->index();
            }
        });

        Schema::table('payments', function (Blueprint $t) {
            if (!Schema::hasColumn('payments','stripe_payment_method_id')) {
                $t->string('stripe_payment_method_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // Only drop if present to be safe on rollbacks
        Schema::table('customers', function (Blueprint $t) {
            if (Schema::hasColumn('customers','stripe_customer_id')) {
                $t->dropIndex(['stripe_customer_id']);
                $t->dropColumn('stripe_customer_id');
            }
        });

        Schema::table('payments', function (Blueprint $t) {
            if (Schema::hasColumn('payments','stripe_payment_method_id')) {
                $t->dropIndex(['stripe_payment_method_id']);
                $t->dropColumn('stripe_payment_method_id');
            }
        });
    }
};

