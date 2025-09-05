<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::create('job_access_tokens', function (Blueprint $table) {
      $table->id();
      $table->foreignId('job_id')->constrained()->cascadeOnDelete();
      $table->uuid('token')->unique();
      $table->string('purpose')->default('pay');
      $table->timestamp('expires_at')->nullable()->index();
      $table->timestamp('used_at')->nullable();
      $table->timestamps();
      $table->index(['job_id','purpose']);
    });
  }
  public function down(): void {
    Schema::dropIfExists('job_access_tokens');
  }
};
