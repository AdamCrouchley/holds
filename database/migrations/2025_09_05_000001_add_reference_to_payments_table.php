<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payments')) return;

        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'reference')) {
                $table->string('reference')->nullable()->after('job_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payments')) return;

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'reference')) {
                $table->dropColumn('reference');
            }
        });
    }
};
