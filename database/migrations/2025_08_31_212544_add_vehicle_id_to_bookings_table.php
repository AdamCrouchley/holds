<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// migration up()
Schema::table('bookings', function (Blueprint $table) {
    $table->unsignedBigInteger('vehicle_id')->nullable()->index()->after('vehicle');
});
