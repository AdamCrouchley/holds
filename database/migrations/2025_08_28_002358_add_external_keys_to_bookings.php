<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            if (!Schema::hasColumn('bookings', 'external_source')) {
                $t->string('external_source')->nullable()->index();
            }
            if (!Schema::hasColumn('bookings', 'external_id')) {
                $t->string('external_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            if (Schema::hasColumn('bookings', 'external_id')) {
                $t->dropColumn('external_id');
            }
            if (Schema::hasColumn('bookings', 'external_source')) {
                $t->dropColumn('external_source');
            }
        });
    }
};
