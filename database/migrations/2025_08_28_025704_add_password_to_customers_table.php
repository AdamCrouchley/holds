<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_password_to_customers_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
        });
    }
    public function down(): void {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
