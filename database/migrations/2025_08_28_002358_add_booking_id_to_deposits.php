<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Add nullable booking_id + FK → bookings(id)
        Schema::table('deposits', function (Blueprint $table) {
            if (!Schema::hasColumn('deposits', 'booking_id')) {
                $table->foreignId('booking_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('bookings')
                    ->cascadeOnUpdate()
                    ->nullOnDelete(); // keep deposit history if a booking is deleted
            }
        });

        // 2) Backfill booking_id from booking_reference (if present)
        if (Schema::hasColumn('deposits', 'booking_id') && Schema::hasColumn('deposits', 'booking_reference')) {
            DB::table('deposits')
                ->whereNull('booking_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        if (!empty($row->booking_reference)) {
                            $bookingId = DB::table('bookings')
                                ->where('reference', $row->booking_reference)
                                ->value('id');

                            if ($bookingId) {
                                DB::table('deposits')
                                    ->where('id', $row->id)
                                    ->update(['booking_id' => $bookingId]);
                            }
                        }
                    }
                }, 'id');
        }

        // 3) (Optional, do later after verifying backfill)
        // Schema::table('deposits', function (Blueprint $table) {
        //     $table->foreignId('booking_id')->nullable(false)->change();
        //     $table->dropColumn('booking_reference');
        // });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            if (Schema::hasColumn('deposits', 'booking_id')) {
                // Drop FK then column (wrapped in try in case of SQLite)
                try { $table->dropForeign(['booking_id']); } catch (\Throwable $e) {}
                try { $table->dropColumn('booking_id'); } catch (\Throwable $e) {}
            }
        });
    }
};
