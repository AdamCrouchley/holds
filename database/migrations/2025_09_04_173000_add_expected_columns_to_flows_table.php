<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('flows')) {
            // Safety: if table somehow doesn't exist yet, create a minimal one.
            Schema::create('flows', function (Blueprint $table) {
                $table->id();
                $table->string('name')->index();
                $table->integer('hold_amount_cents')->nullable(); // expected by UI
                $table->json('meta')->nullable();
                $table->timestamps();
            });
            return;
        }

        Schema::table('flows', function (Blueprint $table) {
            if (! Schema::hasColumn('flows', 'hold_amount_cents')) {
                $table->integer('hold_amount_cents')->nullable()->after('name');
            }
            if (! Schema::hasColumn('flows', 'meta')) {
                $table->json('meta')->nullable()->after('hold_amount_cents');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('flows')) {
            Schema::table('flows', function (Blueprint $table) {
                if (Schema::hasColumn('flows', 'hold_amount_cents')) {
                    $table->dropColumn('hold_amount_cents');
                }
                if (Schema::hasColumn('flows', 'meta')) {
                    $table->dropColumn('meta');
                }
            });
        }
    }
};
