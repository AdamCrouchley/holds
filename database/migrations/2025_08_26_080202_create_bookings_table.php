<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->nullOnDelete();

            $table->string('reference')->unique();
            $table->string('vehicle')->nullable();

            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            $table->integer('total_amount')->nullable();     // cents
            $table->integer('deposit_amount')->nullable();   // cents
            $table->integer('hold_amount')->nullable();      // cents (bond), optional

            $table->string('currency', 8)->default('NZD');
            $table->string('status', 32)->default('pending');

            // external linking (your feed)
            $table->string('external_source')->nullable();
            $table->string('external_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
