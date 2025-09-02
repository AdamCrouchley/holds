<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            // Track Stripe objects on each payment/hold/capture/refund
            if (!Schema::hasColumn('payments','stripe_payment_intent_id')) {
                $t->string('stripe_payment_intent_id')->nullable()->index();
            }
            if (!Schema::hasColumn('payments','stripe_payment_method_id')) {
                $t->string('stripe_payment_method_id')->nullable()->index();
            }
            // Optional: if your table lacks these
            if (!Schema::hasColumn('payments','currency')) {
                $t->string('currency', 8)->nullable()->index();
            }
            if (!Schema::hasColumn('payments','status')) {
                $t->string('status', 32)->nullable()->index(); // e.g. succeeded, requires_capture
            }
            if (!Schema::hasColumn('payments','purpose') && !Schema::hasColumn('payments','type')) {
                $t->string('purpose', 64)->nullable()->index(); // e.g. deposit, balance, hold, hold_capture, refund
            }
            // If foreign keys are missing in your schema, uncomment as needed:
            // if (!Schema::hasColumn('payments','booking_id'))  $t->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            // if (!Schema::hasColumn('payments','customer_id')) $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            if (Schema::hasColumn('payments','stripe_payment_method_id')) $t->dropColumn('stripe_payment_method_id');
            if (Schema::hasColumn('payments','stripe_payment_intent_id')) $t->dropColumn('stripe_payment_intent_id');
            // Optionally drop the others if you added them here:
            // if (Schema::hasColumn('payments','purpose'))  $t->dropColumn('purpose');
            // if (Schema::hasColumn('payments','status'))   $t->dropColumn('status');
            // if (Schema::hasColumn('payments','currency')) $t->dropColumn('currency');
        });
    }
};
