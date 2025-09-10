<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('deposits')) return;

        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();

            $table->integer('amount_cents')->default(0);
            $table->string('currency', 10)->default('nzd');

            $table->enum('status', ['authorized','captured','released','canceled','failed'])
                  ->default('authorized')->index();

            $table->string('stripe_payment_intent', 255)->nullable()->index();
            $table->string('stripe_payment_method', 255)->nullable()->index();

            $table->string('card_brand', 50)->nullable();
            $table->string('last4', 8)->nullable();

            $table->timestamp('authorized_at')->nullable()->index();
            $table->timestamp('captured_at')->nullable()->index();
            $table->timestamp('released_at')->nullable()->index();
            $table->timestamp('canceled_at')->nullable()->index();

            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            // Optional FKs if your tables exist (guarded)
            // $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
            // $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('deposits')) return;
        Schema::dropIfExists('deposits');
    }
};
