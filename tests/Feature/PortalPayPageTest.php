<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalPayPageTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function pay_page_loads_for_valid_token()
    {
        $c = Customer::factory()->create();
        $booking = Booking::create([
            'customer_id'=>$c->id,'vehicle'=>'Tesla','start_at'=>now(),'end_at'=>now()->addDay(),
            'total_amount'=>1000,'deposit_amount'=>500,'hold_amount'=>1500,'currency'=>'NZD',
            'portal_token'=>Str::random(40),
        ]);

        $res = $this->get(route('portal.pay', $booking->portal_token));
        $res->assertOk();
        $res->assertSee((string)$booking->reference);
    }
}
