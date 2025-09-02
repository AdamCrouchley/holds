<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // New mechanism column for how the payment was made/handled
            // (e.g. 'card', 'hold', 'refund', 'bank_transfer', etc.)
            if (! Schema::hasColumn('payments', 'mechanism')) {
                $table->string('mechanism')->nullable()->after('type');
            }

            // Optional: add a 'purpose' column if you want to separate it from 'type'
            // If you intend to KEEP type as purpose, you can skip this.
            if (! Schema::hasColumn('payments', 'purpose')) {
                $table->string('purpose')->nullable()->after('mechanism');
            }

            // If you have a CHECK constraint on `type` (SQLite or custom),
            // leave it alone; we’re no longer writing 'card' into `type`.
            // We will backfill mechanism instead.
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'purpose')) {
                $table->dropColumn('purpose');
            }
            if (Schema::hasColumn('payments', 'mechanism')) {
                $table->dropColumn('mechanism');
            }
        });
    }
};
