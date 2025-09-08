<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        // Create the business_jobs table if it doesn't exist
        if (! Schema::hasTable('business_jobs')) {
            Schema::create('business_jobs', function (Blueprint $table) {
                $table->id();

                // Cross-system reference (shared with bookings/payments)
                $table->string('reference', 120)->nullable()->index();

                // Optional relationships (kept nullable and indexed; no FKs for portability)
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->unsignedBigInteger('flow_id')->nullable()->index();

                // Descriptive fields
                $table->string('title', 200)->nullable();

                // Status (free-form string to avoid enum headaches across DBs)
                // examples: draft | pending | active | completed | cancelled
                $table->string('status', 50)->default('pending')->index();

                // Money in cents (integer), currency ISO code
                $table->bigInteger('amount_cents')->nullable();
                $table->string('currency', 3)->default('NZD');

                // Scheduling (nullable)
                $table->timestamp('start_at')->nullable()->index();
                $table->timestamp('end_at')->nullable()->index();

                // Notes / metadata
                $table->text('notes')->nullable();
                $table->json('meta')->nullable();

                $table->timestamps();

                // Helpful compound index for lookups by reference + status
                $table->index(['reference', 'status']);
            });

            return;
        }

        // If the table already exists, add any missing columns safely
        Schema::table('business_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('business_jobs', 'reference')) {
                $table->string('reference', 120)->nullable()->index();
            }
            if (! Schema::hasColumn('business_jobs', 'booking_id')) {
                $table->unsignedBigInteger('booking_id')->nullable()->index();
            }
            if (! Schema::hasColumn('business_jobs', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->index();
            }
            if (! Schema::hasColumn('business_jobs', 'flow_id')) {
                $table->unsignedBigInteger('flow_id')->nullable()->index();
            }
            if (! Schema::hasColumn('business_jobs', 'title')) {
                $table->string('title', 200)->nullable();
            }
            if (! Schema::hasColumn('business_jobs', 'status')) {
                $table->string('status', 50)->default('pending')->index();
            }
            if (! Schema::hasColumn('business_jobs', 'amount_cents')) {
                $table->bigInteger('amount_cents')->nullable();
            }
            if (! Schema::hasColumn('business_jobs', 'currency')) {
                $table->string('currency', 3)->default('NZD');
            }
            if (! Schema::hasColumn('business_jobs', 'start_at')) {
                $table->timestamp('start_at')->nullable()->index();
            }
            if (! Schema::hasColumn('business_jobs', 'end_at')) {
                $table->timestamp('end_at')->nullable()->index();
            }
            if (! Schema::hasColumn('business_jobs', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('business_jobs', 'meta')) {
                $table->json('meta')->nullable();
            }
            if (! Schema::hasColumn('business_jobs', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_jobs');
    }
};
