<?php

namespace Database\Factories;

use App\Enums\CheckStatus;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckIssuance>
 */
class CheckIssuanceFactory extends Factory
{
    protected $model = CheckIssuance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_account_id' => BankAccount::factory(),
            'check_date' => fake()->dateTimeBetween('-2 months', 'now')->format('Y-m-d'),
            'serial_no' => (string) fake()->unique()->numerify('#########'),
            'payee' => fake()->company(),
            'uacs_object_code' => '5020202000',
            'amount' => fake()->randomFloat(2, 1000, 500000),
            'status' => CheckStatus::Outstanding,
        ];
    }

    public function status(CheckStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
