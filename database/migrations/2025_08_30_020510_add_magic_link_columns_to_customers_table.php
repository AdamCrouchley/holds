<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns only if they don't already exist (safe if other migrations overlap)
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'login_token')) {
                $table->string('login_token', 64)->nullable()->index();
            }

            if (! Schema::hasColumn('customers', 'login_token_expires_at')) {
                $table->dateTime('login_token_expires_at')->nullable()->index();
            }

            if (! Schema::hasColumn('customers', 'portal_last_login_at')) {
                $table->dateTime('portal_last_login_at')->nullable()->index();
            }

            if (! Schema::hasColumn('customers', 'portal_last_seen_at')) {
                $table->dateTime('portal_last_seen_at')->nullable()->index();
            }

            if (! Schema::hasColumn('customers', 'portal_timezone')) {
                $table->string('portal_timezone', 64)->nullable();
            }

            if (! Schema::hasColumn('customers', 'portal_magic_redirect')) {
                $table->string('portal_magic_redirect')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Drop only if present (helps with SQLite and repeated rollbacks)
        Schema::table('customers', function (Blueprint $table) {
            $drops = [];

            foreach ([
                'login_token',
                'login_token_expires_at',
                'portal_last_login_at',
                'portal_last_seen_at',
                'portal_timezone',
                'portal_magic_redirect',
            ] as $col) {
                if (Schema::hasColumn('customers', $col)) {
                    $drops[] = $col;
                }
            }

            if (! empty($drops)) {
                $table->dropColumn($drops);
            }
        });
    }
};
