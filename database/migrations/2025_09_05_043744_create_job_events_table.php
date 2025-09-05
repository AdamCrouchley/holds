<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('job_events')) {
            return; // already created
        }

        Schema::create('job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs')->cascadeOnDelete();
            $table->string('type')->index();          // e.g. "hold.created", "email.sent"
            $table->json('payload')->nullable();      // arbitrary event data
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();

            // Helpful composite index for common queries
            $table->index(['job_id', 'type']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('job_events')) {
            return;
        }
        Schema::dropIfExists('job_events');
    }
};
