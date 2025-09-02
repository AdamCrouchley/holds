<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Skip if the column already exists (prevents duplicate column error on SQLite)
        if (Schema::hasColumn('bookings', 'portal_token')) {
            // (Optional) ensure unique index exists without blowing up on SQLite
            try {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS bookings_portal_token_unique ON bookings(portal_token)');
            } catch (\Throwable $e) {
                // ignore if adapter doesn’t support IF NOT EXISTS
            }
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('portal_token', 64)->nullable();
        });

        // Add unique index separately for better cross-DB behavior
        try {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS bookings_portal_token_unique ON bookings(portal_token)');
        } catch (\Throwable $e) {
            // Fallback for MySQL/Postgres if needed:
            // Schema::table('bookings', fn (Blueprint $t) => $t->unique('portal_token'));
        }
    }

    public function down(): void
    {
        // Drop unique index if it exists, then drop the column
        try {
            DB::statement('DROP INDEX IF EXISTS bookings_portal_token_unique');
        } catch (\Throwable $e) {
            // ignore if not supported
        }

        if (Schema::hasColumn('bookings', 'portal_token')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('portal_token');
            });
        }
    }
};
