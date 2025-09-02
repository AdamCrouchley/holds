<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deposits')) return;

        Schema::table('deposits', function (Blueprint $table) {
            if (! Schema::hasColumn('deposits', 'booking_id')) {
                $table->unsignedBigInteger('booking_id')->nullable()->after('id');
                $table->index('booking_id', 'deposits_booking_id_index');
            }
        });

        if (Schema::hasColumn('deposits', 'booking_reference')) {
            DB::table('deposits')
                ->whereNull('booking_id')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        if (empty($row->booking_reference)) continue;
                        $bookingId = DB::table('bookings')
                            ->where('reference', $row->booking_reference)
                            ->value('id');
                        if ($bookingId) {
                            DB::table('deposits')->where('id', $row->id)->update([
                                'booking_id' => $bookingId,
                            ]);
                        }
                    }
                }, 'id');
        }
    }

    public function down(): void
    {
        // On SQLite, dropping columns is painful; leave as no-op.
    }
};
