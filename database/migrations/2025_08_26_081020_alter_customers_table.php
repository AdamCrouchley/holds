<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Add columns only if missing (safe in repeated test runs)
            if (! Schema::hasColumn('customers', 'first_name')) {
                $table->string('first_name')->nullable();
            }
            if (! Schema::hasColumn('customers', 'last_name')) {
                $table->string('last_name')->nullable();
            }
            if (! Schema::hasColumn('customers', 'email')) {
                $table->string('email')->unique()->nullable();
            }
            if (! Schema::hasColumn('customers', 'phone')) {
                $table->string('phone')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Drop only if they exist
            if (Schema::hasColumn('customers', 'first_name')) $table->dropColumn('first_name');
            if (Schema::hasColumn('customers', 'last_name'))  $table->dropColumn('last_name');
            if (Schema::hasColumn('customers', 'email'))      $table->dropColumn('email');
            if (Schema::hasColumn('customers', 'phone'))      $table->dropColumn('phone');
        });
    }
};
