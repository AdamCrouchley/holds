<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop the old table if it exists (dev-only convenience)
        Schema::dropIfExists('customers');

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('email')->index();
            $t->string('phone')->nullable();
            $t->string('stripe_customer_id')->nullable()->index();
            $t->string('default_payment_method_id')->nullable()->index();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
