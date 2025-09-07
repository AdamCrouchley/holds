<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('flows') && ! Schema::hasColumn('flows', 'deleted_at')) {
            Schema::table('flows', function (Blueprint $table) {
                $table->softDeletes(); // adds nullable 'deleted_at' TIMESTAMP
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('flows') && Schema::hasColumn('flows', 'deleted_at')) {
            Schema::table('flows', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
