<?php

namespace App\Console\Commands\Notifications;

use Illuminate\Console\Command;
use App\Models\Booking;

class CustomerPickupReminder extends Command
{
    protected $signature = 'notify:customer-pickup';
    protected $description = 'Send pickup reminders to customers (day before).';

    public function handle(): int
    {
        $tomorrow = now()->addDay()->toDateString();

        $bookings = Booking::whereDate('start_at', $tomorrow)->get();

        foreach ($bookings as $b) {
            // TODO: send proper notification
            $this->line("Reminder: send pickup email to {$b->customer?->email} (Ref: {$b->reference})");
        }

        return Command::SUCCESS;
    }
}
