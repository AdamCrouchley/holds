<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'pgsql'])) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('booking_id')->nullable()->change();
            });
        } else {
            // SQLite can't "change()" nullability. Quick, safe workaround:
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'booking_id')) return;
                // Add a shadow column that IS nullable
                if (! Schema::hasColumn('payments', 'booking_id_nullable')) {
                    $table->unsignedBigInteger('booking_id_nullable')->nullable()->after('id');
                }
            });

            // Copy data across
            \DB::statement('UPDATE payments SET booking_id_nullable = booking_id');

            Schema::table('payments', function (Blueprint $table) {
                // Drop the old FK/column if possible, then rename the shadow column

            });

            Schema::table('payments', function (Blueprint $table) {
                $table->renameColumn('booking_id_nullable', 'booking_id');
            });
        }
    }

    public function down(): void
    {
        // Reverting to NOT NULL is risky if rows are null; skip for safety.
    }
};
