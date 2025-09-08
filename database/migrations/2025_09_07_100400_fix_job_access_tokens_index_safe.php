<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            $exists = DB::table('information_schema.STATISTICS')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'job_access_tokens')
                ->where('INDEX_NAME', 'job_access_tokens_job_id_purpose_unique')
                ->exists();

            if ($exists) {
                DB::statement('ALTER TABLE job_access_tokens DROP INDEX job_access_tokens_job_id_purpose_unique');
            }
        }

        // (Optional) ensure the index you actually want exists:
        // DB::statement('ALTER TABLE job_access_tokens ADD UNIQUE INDEX job_access_tokens_job_id_purpose_unique (job_id, purpose)');
    }

    public function down(): void
    {
        // no-op
    }
};
