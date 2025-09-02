<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->integer('amount');                     // cents
            $t->string('currency',3)->default('NZD');
            $t->string('stripe_payment_intent_id')->nullable()->index();
            $t->enum('status',['authorised','captured','voided','expired'])->index();
            $t->timestamp('authorised_at')->nullable();
            $t->timestamp('expires_at')->nullable();   // estimate ~7 days
            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('deposits');
    }
};
