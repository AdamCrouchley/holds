<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->nullable()->index();
                $table->foreignId('job_id')->nullable()->index();
                $table->foreignId('customer_id')->nullable()->index();
                $table->string('reference', 120)->nullable()->index();
                $table->integer('amount_cents')->default(0);
                $table->string('currency', 10)->default('NZD');
                $table->string('status', 50)->default('pending')->index();
                $table->string('type', 50)->nullable()->index();
                $table->string('purpose', 120)->nullable()->index();
                $table->string('mechanism', 50)->nullable()->index();
                $table->string('stripe_payment_intent_id', 191)->nullable()->index();
                $table->string('stripe_payment_method_id', 191)->nullable()->index();
                $table->string('stripe_charge_id', 191)->nullable()->index();
                $table->json('details')->nullable();
                $table->timestamps();
                $table->index(['reference', 'status']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::drop('payments');
        }
    }
};
