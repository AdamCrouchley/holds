<?php

namespace App\Console\Commands\Notifications;

use Illuminate\Console\Command;
use App\Models\Booking;

class CustomerReturnReminder extends Command
{
    protected $signature = 'notify:customer-return';
    protected $description = 'Send return reminders to customers (on the morning of return).';

    public function handle(): int
    {
        $today = now()->toDateString();

        $bookings = Booking::whereDate('end_at', $today)->get();

        foreach ($bookings as $b) {
            // TODO: send proper notification
            $this->line("Reminder: send return email to {$b->customer?->email} (Ref: {$b->reference})");
        }

        return Command::SUCCESS;
    }
}
