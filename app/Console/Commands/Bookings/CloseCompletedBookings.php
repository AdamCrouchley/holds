<?php

namespace App\Console\Commands\Bookings;

use Illuminate\Console\Command;
use App\Models\Booking;
use Carbon\Carbon;

class CloseCompletedBookings extends Command
{
    protected $signature = 'bookings:close-completed';
    protected $description = 'Mark bookings as completed once the end date has passed.';

    public function handle(): int
    {
        $now = Carbon::now();

        $count = Booking::where('status', 'paid')
            ->where('end_at', '<', $now)
            ->update(['status' => 'completed']);

        $this->info("Closed {$count} completed bookings.");

        return Command::SUCCESS;
    }
}
