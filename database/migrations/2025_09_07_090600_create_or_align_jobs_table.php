<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->id();

                // Core
                $table->string('title')->nullable();          // if your UI calls it “Job Name”, keep as string
                $table->string('reference')->nullable()->index();

                // Relationships (keep nullable; avoid FK constraint to prevent shared-host issues)
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index(); // if you add customers later

                // Money / amounts
                $table->bigInteger('charge_amount_cents')->nullable();

                // Addresses (you had “billing address” migrations)
                $table->string('billing_name')->nullable();
                $table->string('billing_line1')->nullable();
                $table->string('billing_line2')->nullable();
                $table->string('billing_city')->nullable();
                $table->string('billing_region')->nullable();
                $table->string('billing_postcode')->nullable();
                $table->string('billing_country')->nullable();

                // Status pipeline
                $table->string('status')->default('draft')->index();

                // Misc
                $table->json('meta')->nullable();

                $table->timestamps();
                $table->softDeletes();
            });
        } else {
            // Table exists — add any missing columns your app/resources expect
            Schema::table('jobs', function (Blueprint $table) {
                $cols = Schema::getColumnListing('jobs');

                if (! in_array('reference', $cols)) $table->string('reference')->nullable()->index();
                if (! in_array('booking_id', $cols)) $table->unsignedBigInteger('booking_id')->nullable()->index();
                if (! in_array('charge_amount_cents', $cols)) $table->bigInteger('charge_amount_cents')->nullable();

                foreach ([
                    'billing_name','billing_line1','billing_line2','billing_city',
                    'billing_region','billing_postcode','billing_country'
                ] as $c) {
                    if (! in_array($c, $cols)) $table->string($c)->nullable();
                }

                if (! in_array('status', $cols)) $table->string('status')->default('draft')->index();
                if (! in_array('meta', $cols)) $table->json('meta')->nullable();
                if (! in_array('deleted_at', $cols)) $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        // Be conservative: only drop if it looks like we created it.
        if (Schema::hasTable('jobs')) {
            Schema::drop('jobs');
        }
    }
};
