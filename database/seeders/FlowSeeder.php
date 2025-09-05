<?php

namespace Database\Seeders;

use App\Models\Flow;
use Illuminate\Database\Seeder;

class FlowSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'description' => 'Default flow for standard vehicle hires.',
            'hold_amount_cents' => 100000, // $1,000
            'auto_renew_days' => 7,
            'auto_release_days' => 3,
            'allow_partial_capture' => true,
            'auto_capture_on_damage' => true,
            'auto_cancel_if_no_capture' => true,
            'auto_cancel_after_days' => 14,
            'required_fields' => ['customer_name','customer_email','start_date','end_date'],
            'comms' => [
                'on_create'  => 'email:hold_created',
                'on_release' => 'email:hold_released',
                'on_capture' => 'email:charge_captured',
                'on_cancel'  => 'email:hold_cancelled',
            ],
            'webhooks' => [
                ['event' => 'captured', 'url' => 'https://example.com/webhooks/captured'],
            ],
            'tags' => ['christchurch','standard'],
        ];

        // Use name as the unique key for the default record
        Flow::updateOrCreate(['name' => 'Standard Car Rental Hold'], $defaults);
    }
}
