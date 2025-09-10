<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'provider')) {
                $table->string('provider', 32)->nullable()->index();
            }
            if (!Schema::hasColumn('payments', 'provider_id')) {
                $table->string('provider_id', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'provider')) {
                $table->dropColumn('provider');
            }
            if (Schema::hasColumn('payments', 'provider_id')) {
                $table->dropColumn('provider_id');
            }
        });
    }
};
