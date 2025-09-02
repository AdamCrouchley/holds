<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingRelationsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function booking_belongs_to_a_customer()
    {
        $c = Customer::factory()->create();
        $b = Booking::create([
            'customer_id'=>$c->id,'vehicle'=>'Tesla','start_at'=>now(),'end_at'=>now()->addDay(),
            'total_amount'=>1000,'deposit_amount'=>500,'hold_amount'=>1500,'currency'=>'NZD',
            'portal_token'=>Str::random(40),
        ]);

        $this->assertTrue($b->customer->is($c));
    }
}
