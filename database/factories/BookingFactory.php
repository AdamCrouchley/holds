<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        $start = now()->addDays($this->faker->numberBetween(1, 30));
        $end   = (clone $start)->addDays($this->faker->numberBetween(1, 14));

        return [
            'customer_id'    => Customer::factory(),
            'reference'      => 'BK-'.$this->faker->unique()->numerify('#####'),
            'vehicle'        => $this->faker->randomElement(['Tesla Model Y','Suzuki Jimny','Range Rover Sport']),
            'start_at'       => $start,
            'end_at'         => $end,
            'total_amount'   => $this->faker->numberBetween(10000, 500000), // cents
            'deposit_amount' => $this->faker->numberBetween(5000, 100000),  // cents
            'hold_amount'    => $this->faker->numberBetween(5000, 150000),  // cents
            'currency'       => 'NZD',
            'portal_token'   => Str::random(40),
        ];
    }
}
