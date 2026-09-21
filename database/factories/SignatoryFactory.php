<?php

namespace Database\Factories;

use App\Enums\SignatoryBlock;
use App\Models\BankAccount;
use App\Models\Signatory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signatory>
 */
class SignatoryFactory extends Factory
{
    protected $model = Signatory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'block' => fake()->randomElement(SignatoryBlock::cases()),
            'name' => fake()->name(),
            'designation' => fake()->jobTitle(),
            'sort_order' => 0,
        ];
    }

    public function block(SignatoryBlock $block): static
    {
        return $this->state(fn (array $attributes) => ['block' => $block]);
    }
}
