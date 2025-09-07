<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->string('reference')->nullable()->index();

                // Generic fields that commonly exist on payments:
                $table->integer('amount_cents')->nullable();
                $table->string('currency', 3)->nullable();
                $table->string('status')->nullable();
                $table->json('metadata')->nullable();

                $table->timestamps();
            });
        } else {
            Schema::table('payments', function (Blueprint $table) {
                if (! Schema::hasColumn('payments', 'job_id')) {
                    $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
                }
                if (! Schema::hasColumn('payments', 'booking_id')) {
                    $table->unsignedBigInteger('booking_id')->nullable()->index();
                }
                if (! Schema::hasColumn('payments', 'reference')) {
                    $table->string('reference')->nullable()->index();
                }
                if (! Schema::hasColumn('payments', 'amount_cents')) {
                    $table->integer('amount_cents')->nullable();
                }
                if (! Schema::hasColumn('payments', 'currency')) {
                    $table->string('currency', 3)->nullable();
                }
                if (! Schema::hasColumn('payments', 'status')) {
                    $table->string('status')->nullable();
                }
                if (! Schema::hasColumn('payments', 'metadata')) {
                    $table->json('metadata')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Safe default: keep data.
        // Schema::dropIfExists('payments');
    }
};
