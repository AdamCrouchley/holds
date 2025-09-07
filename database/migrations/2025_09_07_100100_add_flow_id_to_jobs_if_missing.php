<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('jobs') && ! Schema::hasColumn('jobs', 'flow_id')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->foreignId('flow_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('flows')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'flow_id')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('flow_id');
            });
        }
    }
};
