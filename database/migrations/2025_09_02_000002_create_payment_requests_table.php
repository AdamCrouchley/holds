<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['balance','bond','custom'])->default('balance');
            $table->integer('amount')->nullable(); // cents; null = “remaining balance”
            $table->string('currency', 3)->default('NZD');
            $table->timestamp('due_at')->nullable();
            $table->string('status')->default('pending'); // pending|sent|succeeded|cancelled
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('source_system')->nullable();
            $table->string('source_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('payment_requests');
    }
};
