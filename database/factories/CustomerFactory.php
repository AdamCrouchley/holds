<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /** Optionally pin the model (not required if PHPDoc generic above is present) */
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name'  => $this->faker->lastName(),
            'email'      => $this->faker->unique()->safeEmail(),
            'phone'      => $this->faker->phoneNumber(),
        ];
    }

    /** Example state (optional) */
    public function nz(): static
    {
        return $this->state(function () {
            return [
                'phone' => '+64 ' . $this->faker->numberBetween(20, 29) . ' ' . $this->faker->numberBetween(100, 999) . ' ' . $this->faker->numberBetween(1000, 9999),
            ];
        });
    }
}
