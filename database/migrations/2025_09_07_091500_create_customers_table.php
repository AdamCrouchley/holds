<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable()->index();
                $table->string('phone')->nullable();
                $table->json('meta')->nullable();   // room for extras
                $table->timestamps();
                $table->softDeletes();              // Filament expects this often
            });
        } else {
            // align if an older/smaller table exists
            Schema::table('customers', function (Blueprint $table) {
                $cols = Schema::getColumnListing('customers');
                if (! in_array('deleted_at', $cols)) $table->softDeletes();
                if (! in_array('meta', $cols)) $table->json('meta')->nullable();
                if (! in_array('phone', $cols)) $table->string('phone')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::drop('customers');
        }
    }
};
