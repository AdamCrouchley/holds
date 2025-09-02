<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add unique constraints:
     *  - customers.email
     *  - bookings.reference
     *  - bookings.portal_token
     */
    public function up(): void
    {
        // Customers: make email unique (191 already set in create migration)
        Schema::table('customers', function (Blueprint $table) {
            // If you previously had a non-unique index on email, this creates a new unique index.
            // (Unique implies an index; a separate plain index is not necessary.)
            $table->unique('email', 'customers_email_unique');
        });

        // Bookings: unique reference and portal_token
        Schema::table('bookings', function (Blueprint $table) {
            $table->unique('reference', 'bookings_reference_unique');
            $table->unique('portal_token', 'bookings_portal_token_unique');
        });
    }

    /**
     * Revert the unique constraints.
     */
    public function down(): void
    {
        // Customers: drop unique, restore a plain index for email (as it existed before)
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_email_unique');
            $table->index('email', 'customers_email_index');
        });

        // Bookings: drop unique constraints
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_reference_unique');
            $table->dropUnique('bookings_portal_token_unique');
        });
    }
};
