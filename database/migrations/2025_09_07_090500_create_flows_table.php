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
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();

                // Simple enable/disable toggle for Filament tables/filters
                $table->boolean('is_active')->default(true);

                // Room for your builder/logic
                $table->json('definition')->nullable();
                $table->json('meta')->nullable();

                $table->timestamps();
                $table->softDeletes();     // adds deleted_at (your error needed this)
            });
        } else {
            // Ensure soft deletes exist even if table was created earlier
            if (! Schema::hasColumn('flows', 'deleted_at')) {
                Schema::table('flows', function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        // Only drop if you created it here
        if (Schema::hasTable('flows')) {
            Schema::drop('flows');
        }
    }
};
