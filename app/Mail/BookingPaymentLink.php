<?php

// app/Mail/BookingPaymentLink.php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingPaymentLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Complete your booking payment',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking.payment-link',
            with: [
                'booking' => $this->booking,
                'url'     => $this->url,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
