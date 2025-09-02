<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            if (!Schema::hasColumn('customers', 'first_name')) {
                $t->string('first_name')->nullable()->after('id');
            }
            if (!Schema::hasColumn('customers', 'last_name')) {
                $t->string('last_name')->nullable()->after('first_name');
            }
            if (!Schema::hasColumn('customers', 'phone')) {
                $t->string('phone')->nullable()->after('email');
            }
            if (!Schema::hasColumn('customers', 'stripe_customer_id')) {
                $t->string('stripe_customer_id')->nullable()->index()->after('phone');
            }
            if (!Schema::hasColumn('customers', 'default_payment_method_id')) {
                $t->string('default_payment_method_id')->nullable()->index()->after('stripe_customer_id');
            }
            if (!Schema::hasColumn('customers', 'meta')) {
                $t->json('meta')->nullable()->after('default_payment_method_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            if (Schema::hasColumn('customers','first_name')) $t->dropColumn('first_name');
            if (Schema::hasColumn('customers','last_name')) $t->dropColumn('last_name');
            if (Schema::hasColumn('customers','phone')) $t->dropColumn('phone');
            if (Schema::hasColumn('customers','stripe_customer_id')) $t->dropColumn('stripe_customer_id');
            if (Schema::hasColumn('customers','default_payment_method_id')) $t->dropColumn('default_payment_method_id');
            if (Schema::hasColumn('customers','meta')) $t->dropColumn('meta');
        });
    }
};
