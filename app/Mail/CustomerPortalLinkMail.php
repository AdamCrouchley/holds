<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerPortalLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $link,
        public string $note = '',
    ) {}

    public function build()
    {
        return $this->subject('Your customer portal link')
            ->markdown('mail.customer.portal-link', [
                'customer' => $this->customer,
                'link'     => $this->link,
                'note'     => $this->note,
            ]);
    }
}
