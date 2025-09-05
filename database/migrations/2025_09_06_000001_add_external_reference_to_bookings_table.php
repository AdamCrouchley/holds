<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'external_reference')) {
                // Keep it nullable initially; add a unique index later once backfilled if you want
                $table->string('external_reference', 120)->nullable()->index();
            }
        });

        // Backfill something sensible so the Select has values immediately.
        // Here we use "BK-{id}" as a friendly reference if null.
        if (Schema::hasColumn('bookings', 'external_reference')) {
            DB::table('bookings')
                ->whereNull('external_reference')
                ->update([
                    'external_reference' => DB::raw("'BK-' || id") // works in SQLite; use CONCAT for MySQL
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'external_reference')) {
                $table->dropColumn('external_reference');
            }
        });
    }
};
