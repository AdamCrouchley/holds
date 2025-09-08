<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('payments') && !Schema::hasColumn('payments', 'reference')) {
            Schema::table('payments', function (Blueprint $table) {
                // Add reference column (nullable, indexed for lookups)
                $table->string('reference')->nullable()->after('booking_id')->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'reference')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('reference');
            });
        }
    }
};
