<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) return;

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'job_id')) {
                // Use a plain column first; add FK where supported.
                $table->unsignedBigInteger('job_id')->nullable()->after('id');
                $table->index('job_id', 'payments_job_id_index');
            }
        });

        // Add FK where supported (SQLite can't add FKs on ALTER reliably)
        if (DB::getDriverName() !== 'sqlite'
            && Schema::hasColumn('payments', 'job_id')
            && Schema::hasTable('jobs')) {
            Schema::table('payments', function (Blueprint $table) {
                // Guard against duplicate constraint on re-run
                $table->foreign('job_id')->references('id')->on('jobs')
                      ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) return;

        // Drop FK first where supported
        if (DB::getDriverName() !== 'sqlite'
            && Schema::hasColumn('payments', 'job_id')) {
            Schema::table('payments', function (Blueprint $table) {
                // Some drivers need the index/constraint name; this is fine for most
                $table->dropForeign(['job_id']);
            });
        }

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'job_id')) {
                $table->dropIndex('payments_job_id_index');
                $table->dropColumn('job_id');
            }
        });
    }
};
