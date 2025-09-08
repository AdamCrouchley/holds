<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            // Add missing columns without breaking existing data
            if (! Schema::hasColumn('flows', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (! Schema::hasColumn('flows', 'tags')) {
                $table->json('tags')->nullable()->after('description');
            }
            if (! Schema::hasColumn('flows', 'required_fields')) {
                $table->json('required_fields')->nullable()->after('auto_cancel_after_days');
            }
            if (! Schema::hasColumn('flows', 'comms')) {
                $table->json('comms')->nullable()->after('required_fields');
            }
            if (! Schema::hasColumn('flows', 'webhooks')) {
                $table->json('webhooks')->nullable()->after('comms');
            }
        });
    }

    public function down(): void
    {
        // No destructive down on prod
    }
};
