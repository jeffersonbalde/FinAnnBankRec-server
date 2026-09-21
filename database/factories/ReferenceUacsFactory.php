<?php

namespace Database\Factories;

use App\Models\ReferenceUacs;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReferenceUacs>
 */
class ReferenceUacsFactory extends Factory
{
    protected $model = ReferenceUacs::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numerify('50########'),
            'description' => fake()->words(3, true),
            'is_active' => true,
        ];
    }
}
