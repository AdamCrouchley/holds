<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            if (!Schema::hasColumn('bookings', 'customer_id')) {
                $t->unsignedBigInteger('customer_id')->after('id');
            }
            if (!Schema::hasColumn('bookings', 'reference')) {
                $t->string('reference')->unique()->after('customer_id');
            }
            if (!Schema::hasColumn('bookings', 'vehicle')) {
                $t->string('vehicle')->nullable()->after('reference');
            }
            if (!Schema::hasColumn('bookings', 'start_at')) {
                $t->dateTime('start_at')->after('vehicle');
            }
            if (!Schema::hasColumn('bookings', 'end_at')) {
                $t->dateTime('end_at')->after('start_at');
            }
            if (!Schema::hasColumn('bookings', 'total_amount')) {
                $t->integer('total_amount')->after('end_at'); // cents
            }
            if (!Schema::hasColumn('bookings', 'deposit_amount')) {
                $t->integer('deposit_amount')->after('total_amount'); // cents
            }
            if (!Schema::hasColumn('bookings', 'hold_amount')) {
                $t->integer('hold_amount')->default(150000)->after('deposit_amount'); // cents
            }
            if (!Schema::hasColumn('bookings', 'currency')) {
                $t->string('currency', 3)->default('NZD')->after('hold_amount');
            }
            if (!Schema::hasColumn('bookings', 'balance_charged')) {
                $t->boolean('balance_charged')->default(false)->after('currency');
            }
            if (!Schema::hasColumn('bookings', 'portal_token')) {
                $t->string('portal_token')->unique()->after('balance_charged');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            // Drop only what we added (safe checks)
            foreach ([
                'customer_id','reference','vehicle','start_at','end_at',
                'total_amount','deposit_amount','hold_amount','currency',
                'balance_charged','portal_token'
            ] as $col) {
                if (Schema::hasColumn('bookings', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
