<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DepositReceipt extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking, public Payment $payment) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deposit received for booking '.$this->booking->reference)
            ->greeting('Thanks!')
            ->line('We received your booking deposit.')
            ->line('Booking: '.$this->booking->reference)
            ->line('Amount: '.number_format($this->payment->amount/100,2).' '.$this->payment->currency)
            ->action('Manage your card', route('portal.manage', $this->booking->portal_token))
            ->line('See you at pickup!');
    }
}
