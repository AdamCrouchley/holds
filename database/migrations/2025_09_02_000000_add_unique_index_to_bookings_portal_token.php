<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // If the index already exists, this will error — wrap in try/catch if you prefer.
            if (! $this->hasIndex('bookings', 'bookings_portal_token_unique')) {
                $table->unique('portal_token', 'bookings_portal_token_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if ($this->hasIndex('bookings', 'bookings_portal_token_unique')) {
                $table->dropUnique('bookings_portal_token_unique');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $schema = Schema::getConnection()->getDoctrineSchemaManager();
        $indexes = $schema->listTableIndexes($table);
        return array_key_exists($index, $indexes);
    }
};
