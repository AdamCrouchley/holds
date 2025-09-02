<?php

namespace App\Console\Commands\Bookings;

use Illuminate\Console\Command;
use App\Models\Booking;
use Carbon\Carbon;

class AutoCancelBookings extends Command
{
    protected $signature = 'bookings:auto-cancel {--hours=24 : Cancel bookings pending longer than this many hours}';
    protected $description = 'Cancel stale/unpaid bookings that have been pending too long.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subHours((int) $this->option('hours'));

        $count = Booking::where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->update(['status' => 'cancelled']);

        $this->info("Cancelled {$count} stale bookings older than {$this->option('hours')}h.");

        return Command::SUCCESS;
    }
}
