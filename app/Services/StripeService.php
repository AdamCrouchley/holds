<?php

namespace App\Services;

use Stripe\StripeClient;

class StripeService
{
    public StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret'));
    }
}
