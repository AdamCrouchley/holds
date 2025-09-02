<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('automation_settings', function (Blueprint $table) {
            $table->id();
            // For multi-tenant you could add team_id/account_id here
            $table->boolean('active')->default(true);
            $table->unsignedTinyInteger('send_balance_days_before')->default(7);
            $table->unsignedTinyInteger('send_bond_days_before')->default(2);
            $table->time('send_at_local')->default('09:00:00'); // daily window start
            $table->string('timezone')->default('Pacific/Auckland');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('automation_settings');
    }
};
