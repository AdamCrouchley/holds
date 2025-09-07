<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('flows')) {
            Schema::create('flows', function (Blueprint $table) {
                $table->id();
                $table->string('name')->index();
                $table->json('config')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        } else {
            // If table exists but soft deletes column is missing, add it.
            if (! Schema::hasColumn('flows', 'deleted_at')) {
                Schema::table('flows', function (Blueprint $table) {
                    $table->softDeletes()->after('updated_at');
                });
            }
        }
    }

    public function down(): void
    {
        // No-op by default (safer). Uncomment to allow dropping in non-prod.
        // Schema::dropIfExists('flows');
    }
};
