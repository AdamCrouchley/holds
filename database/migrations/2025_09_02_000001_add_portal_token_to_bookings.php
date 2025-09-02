<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'portal_token')) {
                $table->string('portal_token', 64)->unique()->index()->after('vehicle');
            }
        });
    }

    public function down(): void {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'portal_token')) {
                $table->dropUnique(['portal_token']);
                $table->dropColumn('portal_token');
            }
        });
    }
};
