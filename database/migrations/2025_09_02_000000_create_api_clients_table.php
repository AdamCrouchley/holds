<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token')->unique(); // store raw for simplicity (hash in prod)
            $table->json('scopes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('api_clients');
    }
};
