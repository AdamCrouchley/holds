<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Dev convenience: drop the old minimal table
        Schema::dropIfExists('bookings');

        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id');
            $t->string('reference')->unique();
            $t->string('vehicle')->nullable();
            $t->dateTime('start_at');
            $t->dateTime('end_at');
            $t->integer('total_amount');        // cents
            $t->integer('deposit_amount');      // cents
            $t->integer('hold_amount')->default(150000); // cents
            $t->string('currency', 3)->default('NZD');
            $t->boolean('balance_charged')->default(false);
            $t->string('portal_token')->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
