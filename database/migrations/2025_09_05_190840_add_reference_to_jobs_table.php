<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

public function up(): void
{
    // … whatever else you already do …

    Schema::table('payments', function (Blueprint $table) {
        // On SQLite, avoid rename/drop patterns that cause duplicate-name issues
        if (DB::getDriverName() === 'sqlite') {
            // Ensure the column exists; if it already does, don't touch it
            if (! Schema::hasColumn('payments', 'booking_id')) {
                $table->unsignedBigInteger('booking_id')->nullable()->index()->after('job_id');
            }
            // DO NOT attempt rename/drop on SQLite
            return;
        }

        // MySQL/MariaDB path (safe to leave if you really need it):
        if (! Schema::hasColumn('payments', 'booking_id')) {
            $table->unsignedBigInteger('booking_id')->nullable()->index()->after('job_id');
        }
        // If you previously created booking_id_nullable and want to rename on MySQL only:
        // if (Schema::hasColumn('payments', 'booking_id_nullable') && ! Schema::hasColumn('payments', 'booking_id')) {
        //     $table->renameColumn('booking_id_nullable', 'booking_id');
        // }
    });
}

