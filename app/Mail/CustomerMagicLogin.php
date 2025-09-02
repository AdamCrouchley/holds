<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerMagicLogin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Customer $customer, public string $url) {}

    public function build()
    {
        return $this->subject('Your secure sign-in link')
            ->markdown('mail.portal.magic', [
                'customer' => $this->customer,
                'url'      => $this->url,
            ]);
    }
}
