// database/migrations/2025_09_06_000000_add_booking_id_to_payments_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // booking_id
            if (! Schema::hasColumn('payments', 'booking_id')) {
                $table->foreignId('booking_id')
                    ->nullable()
                    ->constrained('bookings')
                    ->nullOnDelete();
            }

            // job_id (add if you plan to link payments to jobs too)
            if (! Schema::hasColumn('payments', 'job_id')) {
                $table->foreignId('job_id')
                    ->nullable()
                    ->constrained('jobs')
                    ->nullOnDelete();
            }

            // amount_cents (ensure correct column name/type)
            if (Schema::hasColumn('payments', 'amount')) {
                // If you previously used 'amount' (integer cents), you can rename it:
                // NOTE: renameColumn requires doctrine/dbal in some DBs; fallback to add+copy if needed.
                try {
                    $table->renameColumn('amount', 'amount_cents');
                } catch (\Throwable $e) {
                    // Fallback: create if missing
                    if (! Schema::hasColumn('payments', 'amount_cents')) {
                        $table->integer('amount_cents')->nullable();
                    }
                }
            } elseif (! Schema::hasColumn('payments', 'amount_cents')) {
                $table->integer('amount_cents')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Drop FKs/columns in a DB-safe way
            if (Schema::hasColumn('payments', 'booking_id')) {
                // SQLite cannot drop constrained columns the same way
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->dropConstrainedForeignId('booking_id');
                } else {
                    $table->dropColumn('booking_id');
                }
            }

            if (Schema::hasColumn('payments', 'job_id')) {
                if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                    $table->dropConstrainedForeignId('job_id');
                } else {
                    $table->dropColumn('job_id');
                }
            }

            // Optionally revert amount_cents -> amount
            // if (Schema::hasColumn('payments', 'amount_cents')) {
            //     try {
            //         $table->renameColumn('amount_cents', 'amount');
            //     } catch (\Throwable $e) {
            //         // ignore in down
            //     }
            // }
        });
    }
};
