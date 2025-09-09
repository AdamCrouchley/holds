<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If the table doesn't exist yet, nothing to do.
        if (! Schema::hasTable('payments')) {
            return;
        }

        // Detect current columns in the live table
        $cols = collect(Schema::getColumnListing('payments'))->flip();

        // Build expressions to SELECT from the old table into the new one.
        // For columns that might not exist yet, fall back to NULL or a default and alias them.
        $idExpr        = 'id';
        $jobIdExpr     = $cols->has('job_id')   ? 'job_id'   : 'NULL AS job_id';
        $bookingIdExpr = $cols->has('booking_id') ? 'booking_id' : 'NULL AS booking_id';
        $referenceExpr = $cols->has('reference') ? 'reference' : 'NULL AS reference';

        // amount_cents may not exist; some schemas used `amount`.
        if ($cols->has('amount_cents')) {
            $amountCentsExpr = 'amount_cents';
        } elseif ($cols->has('amount')) {
            $amountCentsExpr = 'amount AS amount_cents';
        } else {
            $amountCentsExpr = '0 AS amount_cents';
        }

        $currencyExpr  = $cols->has('currency')  ? 'currency'  : "NULL AS currency";
        $statusExpr    = $cols->has('status')    ? 'status'    : "NULL AS status";
        $typeExpr      = $cols->has('type')      ? 'type'      : "NULL AS type";
        $mechExpr      = $cols->has('mechanism') ? 'mechanism' : "NULL AS mechanism";

        $piExpr        = $cols->has('stripe_payment_intent_id')  ? 'stripe_payment_intent_id'  : 'NULL AS stripe_payment_intent_id';
        $pmExpr        = $cols->has('stripe_payment_method_id')  ? 'stripe_payment_method_id'  : 'NULL AS stripe_payment_method_id';
        $chExpr        = $cols->has('stripe_charge_id')          ? 'stripe_charge_id'          : 'NULL AS stripe_charge_id';

        // details might be TEXT/JSON; preserve if present
        $detailsExpr   = $cols->has('details') ? 'details' : 'NULL AS details';

        $createdExpr   = $cols->has('created_at') ? 'created_at' : 'NULL AS created_at';
        $updatedExpr   = $cols->has('updated_at') ? 'updated_at' : 'NULL AS updated_at';

        DB::transaction(function () use (
            $idExpr, $jobIdExpr, $bookingIdExpr, $referenceExpr, $amountCentsExpr, $currencyExpr, $statusExpr,
            $typeExpr, $mechExpr, $piExpr, $pmExpr, $chExpr, $detailsExpr, $createdExpr, $updatedExpr
        ) {
            // 1) Create the new temp table with the desired target schema.
            DB::statement(<<<'SQL'
                CREATE TABLE payments_tmp (
                    id INTEGER PRIMARY KEY,
                    job_id INTEGER NULL,
                    booking_id INTEGER NULL,
                    reference TEXT NULL,
                    amount_cents INTEGER NOT NULL DEFAULT 0,
                    currency TEXT NULL,
                    status TEXT NULL,
                    type TEXT NULL,
                    mechanism TEXT NULL,
                    stripe_payment_intent_id TEXT NULL,
                    stripe_payment_method_id TEXT NULL,
                    stripe_charge_id TEXT NULL,
                    details TEXT NULL,
                    created_at TEXT NULL,
                    updated_at TEXT NULL
                );
            SQL);

            // 2) Copy data from the old table, mapping/aliasing as needed.
            DB::statement("
                INSERT INTO payments_tmp
                    (id, job_id, booking_id, reference, amount_cents, currency, status, type, mechanism,
                     stripe_payment_intent_id, stripe_payment_method_id, stripe_charge_id, details, created_at, updated_at)
                SELECT
                    {$idExpr}, {$jobIdExpr}, {$bookingIdExpr}, {$referenceExpr}, {$amountCentsExpr}, {$currencyExpr}, {$statusExpr}, {$typeExpr}, {$mechExpr},
                    {$piExpr}, {$pmExpr}, {$chExpr}, {$detailsExpr}, {$createdExpr}, {$updatedExpr}
                FROM payments
            ");

            // 3) Swap: drop old and rename new
            DB::statement('DROP TABLE payments;');
            DB::statement('ALTER TABLE payments_tmp RENAME TO payments;');
        });
    }

    public function down(): void
    {
        // This down() can be a no-op for SQLite rebuilds, or you could rebuild back.
    }
};
