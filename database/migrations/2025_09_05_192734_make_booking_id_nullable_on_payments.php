<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropPaymentsBookingFkIfExists();

        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // make booking_id nullable by rebuilding the table (SQLite limitation)
            Schema::disableForeignKeyConstraints();

            // Clean up just in case a previous failed run left this behind
            try { Schema::drop('payments_tmp'); } catch (\Throwable $e) {}

            Schema::create('payments_tmp', function (Blueprint $table) {
                $table->id();

                // Keep the columns you actually have:
                $table->unsignedBigInteger('job_id')->nullable();
                $table->unsignedBigInteger('booking_id')->nullable(); // <-- now nullable

                $table->string('reference', 191)->nullable();

                // Money (stored as cents)
                $table->integer('amount_cents');
                $table->string('currency', 10)->default('NZD');

                // Status & classification (that exist in your table)
                $table->string('status', 50);
                $table->string('type', 50)->nullable();
                $table->string('mechanism', 50)->nullable();

                // Stripe / PSP fields
                $table->string('stripe_payment_intent_id', 191)->nullable();
                $table->string('stripe_payment_method_id', 191)->nullable();
                $table->string('stripe_charge_id', 191)->nullable();

                // Extras
                $table->json('details')->nullable();

                $table->timestamps();
            });

            // Copy ONLY existing columns (no 'purpose')
            DB::statement("
                INSERT INTO payments_tmp
                    (id, job_id, booking_id, reference, amount_cents, currency, status, type, mechanism,
                     stripe_payment_intent_id, stripe_payment_method_id, stripe_charge_id, details, created_at, updated_at)
                SELECT
                    id, job_id, booking_id, reference, amount_cents, currency, status, type, mechanism,
                    stripe_payment_intent_id, stripe_payment_method_id, stripe_charge_id, details, created_at, updated_at
                FROM payments
            ");

            Schema::drop('payments');
            Schema::rename('payments_tmp', 'payments');

            Schema::enableForeignKeyConstraints();
        } else {
            // MySQL/Postgres
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('booking_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            Schema::disableForeignKeyConstraints();

            try { Schema::drop('payments_tmp'); } catch (\Throwable $e) {}

            Schema::create('payments_tmp', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('job_id')->nullable();
                $table->unsignedBigInteger('booking_id'); // NOT NULL again

                $table->string('reference', 191)->nullable();

                $table->integer('amount_cents');
                $table->string('currency', 10)->default('NZD');

                $table->string('status', 50);
                $table->string('type', 50)->nullable();
                $table->string('mechanism', 50)->nullable();

                $table->string('stripe_payment_intent_id', 191)->nullable();
                $table->string('stripe_payment_method_id', 191)->nullable();
                $table->string('stripe_charge_id', 191)->nullable();

                $table->json('details')->nullable();

                $table->timestamps();
            });

            DB::statement("
                INSERT INTO payments_tmp
                    (id, job_id, booking_id, reference, amount_cents, currency, status, type, mechanism,
                     stripe_payment_intent_id, stripe_payment_method_id, stripe_charge_id, details, created_at, updated_at)
                SELECT
                    id, job_id, booking_id, reference, amount_cents, currency, status, type, mechanism,
                    stripe_payment_intent_id, stripe_payment_method_id, stripe_charge_id, details, created_at, updated_at
                FROM payments
            ");

            Schema::drop('payments');
            Schema::rename('payments_tmp', 'payments');

            Schema::enableForeignKeyConstraints();
        } else {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('booking_id')->nullable(false)->change();
            });
        }

        // Optionally re-add FK here if you had one originally.
    }

    private function dropPaymentsBookingFkIfExists(): void
    {
        try {
            Schema::table('payments', function (Blueprint $table) {
                foreach (['payments_booking_id_foreign', 'payments_booking_foreign'] as $name) {
                    try { $table->dropForeign($name); } catch (\Throwable $e) {}
                }
            });
        } catch (\Throwable $e) {}
    }
};
