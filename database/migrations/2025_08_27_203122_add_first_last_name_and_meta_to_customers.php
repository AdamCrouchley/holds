<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Names
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');

            // Optional metadata blob for extra fields from VEVS
            $table->json('meta')->nullable()->after('address_country');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'meta']);
        });
    }
};
