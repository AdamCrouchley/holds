<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingReferenceGenerationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_generates_a_reference_when_missing()
    {
        $c = Customer::factory()->create();

        $b = Booking::create([
            'customer_id'=>$c->id,
            'vehicle'=>'Tesla',
            'start_at'=>now(),
            'end_at'=>now()->addDay(),
            'total_amount'=>1000,
            'deposit_amount'=>500,
            'hold_amount'=>1500,
            'currency'=>'NZD',
            'portal_token'=>Str::random(40),
        ]);

        $this->assertNotEmpty($b->reference);
        $this->assertDatabaseHas('bookings', ['id'=>$b->id, 'reference'=>$b->reference]);
    }

    /** @test */
    public function it_respects_a_manually_supplied_reference()
    {
        $c = Customer::factory()->create();

        $b = Booking::create([
            'customer_id'=>$c->id,
            'reference'=>'BK-MANUAL-1',
            'vehicle'=>'Tesla',
            'start_at'=>now(),
            'end_at'=>now()->addDay(),
            'total_amount'=>1000,
            'deposit_amount'=>500,
            'hold_amount'=>1500,
            'currency'=>'NZD',
            'portal_token'=>Str::random(40),
        ]);

        $this->assertEquals('BK-MANUAL-1', $b->reference);
    }

    /** @test */
    public function generated_references_are_unique_across_many_creates()
    {
        $c = Customer::factory()->create();

        $refs = collect(range(1, 30))->map(function () use ($c) {
            return Booking::create([
                'customer_id'=>$c->id,
                'vehicle'=>'Tesla',
                'start_at'=>now(),
                'end_at'=>now()->addHours(2),
                'total_amount'=>1000,
                'deposit_amount'=>500,
                'hold_amount'=>1500,
                'currency'=>'NZD',
                'portal_token'=>Str::random(40),
            ])->reference;
        });

        $this->assertCount(30, $refs);
        $this->assertCount(30, $refs->unique());
    }
}
