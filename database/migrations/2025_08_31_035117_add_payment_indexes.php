<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Speeds: WHERE booking_id = ? AND mechanism = ?
            $table->index(['booking_id', 'mechanism'], 'payments_booking_mechanism_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_booking_mechanism_idx');
        });
    }
};
