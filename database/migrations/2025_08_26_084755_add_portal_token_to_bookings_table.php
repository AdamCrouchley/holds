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
        Schema::table('bookings', function (Blueprint $table) {
            // Add a nullable unique token field for customer portal access
            $table->string('portal_token', 64)
                  ->unique()
                  ->nullable()
                  ->after('id'); // put it after id (adjust if you prefer another spot)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['portal_token']);
            $table->dropColumn('portal_token');
        });
    }
};
