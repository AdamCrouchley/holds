<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) return;

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'status')) {
                $table->string('status', 32)->nullable()->default('pending')->after('currency');
            }
            if (! Schema::hasColumn('bookings', 'meta')) {
                // JSON on MySQL/PG, TEXT on SQLite — Laravel handles it
                $table->json('meta')->nullable()->after('stripe_payment_intent_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings')) return;

        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'meta')) {
                try { $table->dropColumn('meta'); } catch (\Throwable $e) {}
            }
            if (Schema::hasColumn('bookings', 'status')) {
                try { $table->dropColumn('status'); } catch (\Throwable $e) {}
            }
        });
    }
};
