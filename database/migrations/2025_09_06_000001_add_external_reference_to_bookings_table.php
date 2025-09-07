<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('bookings') && !Schema::hasColumn('bookings', 'external_reference')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('external_reference', 120)
                      ->nullable()
                      ->after('reference')
                      ->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'external_reference')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('external_reference');
            });
        }
    }
};
