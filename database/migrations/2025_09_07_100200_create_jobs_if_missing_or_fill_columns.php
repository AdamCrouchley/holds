<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->nullable()->index();
                $table->integer('charge_amount_cents')->nullable();
                $table->json('billing_address')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        } else {
            Schema::table('jobs', function (Blueprint $table) {
                if (! Schema::hasColumn('jobs', 'reference')) {
                    $table->string('reference')->nullable()->index();
                }
                if (! Schema::hasColumn('jobs', 'charge_amount_cents')) {
                    $table->integer('charge_amount_cents')->nullable();
                }
                if (! Schema::hasColumn('jobs', 'billing_address')) {
                    $table->json('billing_address')->nullable();
                }
                if (! Schema::hasColumn('jobs', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void
    {
        // Safe default: keep data.
        // Schema::dropIfExists('jobs');
    }
};
