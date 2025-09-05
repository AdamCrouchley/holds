<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('jobs') && ! Schema::hasColumn('jobs', 'charge_amount_cents')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->integer('charge_amount_cents')->nullable()->after('hold_amount_cents');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'charge_amount_cents')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropColumn('charge_amount_cents');
            });
        }
    }
};
