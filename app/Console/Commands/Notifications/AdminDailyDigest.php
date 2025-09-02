<?php

namespace App\Console\Commands\Notifications;

use Illuminate\Console\Command;
use App\Models\Booking;
use Illuminate\Support\Facades\Mail;

class AdminDailyDigest extends Command
{
    protected $signature = 'notify:admin-daily-digest';
    protected $description = 'Send a daily summary email to admins with new bookings, pickups, balances due.';

    public function handle(): int
    {
        $today = now()->toDateString();

        $newBookings = Booking::whereDate('created_at', $today)->count();
        $pickups = Booking::whereDate('start_at', $today)->count();
        $balances = Booking::where('status', 'pending')->count();

        // TODO: Replace with proper Mailable
        Mail::raw("Daily Digest:\nNew: {$newBookings}\nPickups: {$pickups}\nBalances pending: {$balances}", function ($msg) {
            $msg->to('admin@example.com')->subject('Daily Booking Digest');
        });

        $this->info("Daily digest sent to admins.");

        return Command::SUCCESS;
    }
}
