<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jobs') && ! Schema::hasColumn('jobs', 'billing_address')) {
            Schema::table('jobs', function (Blueprint $table) {
                // Laravel maps ->json() to TEXT on SQLite — perfect for storing your array.
                $table->json('billing_address')->nullable()->after('customer_phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'billing_address')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropColumn('billing_address');
            });
        }
    }
};
