<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('job_access_tokens')) return;

        Schema::table('job_access_tokens', function (Blueprint $table) {
            // Ensure 'purpose' is index-safe if it's long (utf8mb4).
            if (Schema::hasColumn('job_access_tokens', 'purpose')) {
                // If it's a very long string and the index keeps failing, ensure length is reasonable:
                // (You can skip this alter if purpose is already a normal VARCHAR(191) max).
                // $table->string('purpose', 191)->change();
            }

            // Drop any existing conflicting index names quietly.
            try { $table->dropIndex('job_access_tokens_job_id_purpose_index'); } catch (\Throwable $e) {}
            try { $table->dropUnique('job_access_tokens_job_id_purpose_unique'); } catch (\Throwable $e) {}
            try { $table->dropIndex('job_purpose_unique'); } catch (\Throwable $e) {}

            // Recreate a safe unique index on (job_id, purpose)
            $table->unique(['job_id', 'purpose'], 'job_purpose_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_access_tokens')) return;

        Schema::table('job_access_tokens', function (Blueprint $table) {
            try { $table->dropUnique('job_purpose_unique'); } catch (\Throwable $e) {}
        });
    }
};
