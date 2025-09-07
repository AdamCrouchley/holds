<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('jobs')) {
            Schema::table('jobs', function (Blueprint $table) {
                $cols = Schema::getColumnListing('jobs');
                if (! in_array('customer_id', $cols)) {
                    $table->unsignedBigInteger('customer_id')->nullable()->index()->after('booking_id');
                }
            });
        }
    }
    public function down(): void
    {
        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'customer_id')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->dropColumn('customer_id');
            });
        }
    }
};
