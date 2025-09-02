<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Make booking_id required
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'booking_id')) {
                $table->foreignId('booking_id')->nullable(false)->change();
            }
            // Optionally drop legacy column
            if (Schema::hasColumn('deposits', 'booking_reference')) {
                $table->dropColumn('booking_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            // Reverse of the above (make nullable again, re-add booking_reference if needed)
            $table->foreignId('booking_id')->nullable()->change();
            // $table->string('booking_reference')->nullable(); // only if you truly need to restore it
        });
    }
};
