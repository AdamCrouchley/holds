<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'reference')) {
                $table->string('reference', 120)->nullable()->index()->after('job_id');
            }
            if (! Schema::hasColumn('payments', 'purpose')) {
                $table->string('purpose', 120)->nullable()->after('type');
            }
            if (! Schema::hasColumn('payments', 'mechanism')) {
                $table->string('mechanism', 60)->nullable()->after('purpose');
            }
            // If booking_id was previously dropped somewhere, re-create as nullable to keep inserts happy
            if (! Schema::hasColumn('payments', 'booking_id')) {
                $table->unsignedBigInteger('booking_id')->nullable()->index()->after('job_id');
            }
        });
    }

    public function down(): void
    {
        // Down is intentionally a no-op for SQLite safety
    }
};
