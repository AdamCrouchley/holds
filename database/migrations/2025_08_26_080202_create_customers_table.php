<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();

            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // Email unique (191 chars for safe indexing on utf8mb4)
            $table->string('email', 191)->unique();

            $table->string('phone')->nullable();

            // Stripe identifiers
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('default_payment_method_id')->nullable()->index(); // Stripe PM id

            // Arbitrary extra data
            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
