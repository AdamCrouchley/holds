<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 120)->unique();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('vehicle_id')->nullable();
                $table->timestamp('start_at')->nullable();
                $table->timestamp('end_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
