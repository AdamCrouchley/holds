<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $t) {
                $t->id();

                // Minimal fields to satisfy relations & UI
                $t->unsignedBigInteger('flow_id')->index();   // keep simple for SQLite
                $t->string('status')->default('pending')->index();
                $t->string('external_reference')->nullable()->index();
                $t->string('vehicle_reference')->nullable()->index();

                // Customer basics
                $t->string('customer_name')->nullable();
                $t->string('customer_email')->nullable();
                $t->string('customer_phone')->nullable();

                // Dates (nullable for now)
                $t->dateTime('start_at')->nullable();
                $t->dateTime('end_at')->nullable();
                $t->dateTime('actual_completed_at')->nullable();

                // Financials (minimal)
                $t->integer('hold_amount_cents')->default(0);
                $t->integer('authorized_amount_cents')->default(0);
                $t->integer('captured_amount_cents')->default(0);

                // PSP fields (minimal)
                $t->string('psp')->default('stripe');
                $t->string('psp_authorization_id')->nullable()->index();

                // JSON/meta
                $t->json('meta')->nullable();
                $t->json('comms_log')->nullable();

                $t->softDeletes();
                $t->timestamps();
            });
        }

        // (Optional) If you want a real FK and you're not on old SQLite, you can add it later with a guarded ALTER.
        // if (Schema::hasTable('jobs') && Schema::hasTable('flows')) { ... add foreign key ... }
    }

    public function down(): void
    {
        if (Schema::hasTable('jobs')) {
            Schema::drop('jobs');
        }
    }
};
