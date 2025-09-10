<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('communications')) {
            Schema::create('communications', function (Blueprint $table) {
                $table->id();
                // Optional foreigns—use what you have in Holds:
                $table->foreignId('job_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('deposit_id')->nullable()->constrained()->nullOnDelete();

                $table->string('channel', 20)->default('email'); // 'email' for now
                $table->string('type', 50)->default('payment_request'); // categorize
                $table->string('to_email');
                $table->string('subject')->nullable();

                // Provider mapping
                $table->string('provider', 50)->default('sendgrid');
                $table->string('provider_message_id')->nullable(); // sg_message_id if you capture later
                $table->string('smtp_message_id')->nullable();     // our custom Message-ID for mapping
                $table->string('status', 30)->default('queued');   // queued|sent|delivered|opened|bounced|dropped|complaint|failed

                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['job_id', 'booking_id', 'deposit_id']);
                $table->index(['channel', 'type', 'status']);
                $table->unique(['smtp_message_id']);
            });
        }

        if (!Schema::hasTable('communication_events')) {
            Schema::create('communication_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('communication_id')->constrained('communications')->cascadeOnDelete();
                $table->string('event', 40); // sent, delivered, open, bounce, dropped, spamreport, processed
                $table->timestamp('occurred_at')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['communication_id', 'event']);
            });
        }

        // Optional mirror for your existing Events feed
        if (Schema::hasTable('events')) {
            // No schema change; we’ll just write to it at runtime.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_events');
        Schema::dropIfExists('communications');
    }
};
