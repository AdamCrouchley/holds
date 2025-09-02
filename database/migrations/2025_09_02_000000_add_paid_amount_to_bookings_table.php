<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // store in cents, default 0
            if (!Schema::hasColumn('bookings', 'paid_amount')) {
                $table->integer('paid_amount')->default(0)->after('deposit_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'paid_amount')) {
                $table->dropColumn('paid_amount');
            }
        });
    }
};
