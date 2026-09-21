<?php

namespace Database\Factories;

use App\Enums\ReconciliationStatus;
use App\Models\BankAccount;
use App\Models\Reconciliation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reconciliation>
 */
class ReconciliationFactory extends Factory
{
    protected $model = Reconciliation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-3 months', '-1 month');
        $end = (clone $start)->modify('last day of this month');

        return [
            'bank_account_id' => BankAccount::factory(),
            'period_type' => 'monthly',
            'period_start' => $start->format('Y-m-01'),
            'period_end' => $end->format('Y-m-d'),
            'statement_label' => 'As of '.$end->format('F j, Y'),
            'unadjusted_book_balance' => 0,
            'unadjusted_bank_balance' => 0,
            'status' => ReconciliationStatus::Draft,
            'prepared_by' => User::factory(),
        ];
    }

    public function status(ReconciliationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
