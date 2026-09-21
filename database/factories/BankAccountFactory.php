<?php

namespace Database\Factories;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_name' => 'Land Bank of the Philippines',
            'bank_short_name' => 'LBP',
            'account_number' => (string) fake()->unique()->numerify('####-####-##'),
            'account_name' => 'TECHNICAL EDUCATION AND SKILLS DEVELOPMENT AUTHORITY',
            'entity_name' => 'TESDA-Mis. Occ.',
            'fund_cluster' => fake()->randomElement(['101-GF', '101-MOOE']),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
