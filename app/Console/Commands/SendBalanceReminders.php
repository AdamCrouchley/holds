<?php

// app/Console/Commands/SendBalanceReminders.php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\Booking;
use App\Mail\BookingPaymentLink;
use Illuminate\Support\Facades\Mail;

class SendBalanceReminders extends Command
{
    protected $signature = 'bookings:remind-balance {--days=3}';
    protected $description = 'Email customers to pay remaining balance';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $startFrom = now()->addDays($days)->startOfDay();
        $startTo   = now()->addDays($days)->endOfDay();

        $q = Booking::with('customer','payments')
            ->whereBetween('start_at', [$startFrom, $startTo]);

        $q->chunkById(100, function ($bookings) {
            foreach ($bookings as $b) {
                $paid  = (int) ($b->payments?->whereIn('status',['succeeded','paid','captured','completed'])->sum('amount') ?? 0);
                $total = (int) ($b->total_amount ?? 0);
                if ($total <= $paid) continue;

                $url = route('portal.pay', ['token' => $b->portal_token]);
                if ($b->customer?->email) {
                    Mail::to($b->customer->email)->send(new BookingPaymentLink($b, $url));
                    $this->info("Reminded {$b->reference}");
                }
            }
        });

        return self::SUCCESS;
    }
}
