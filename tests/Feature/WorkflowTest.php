<?php

use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\Reconciliation;
use App\Models\User;
use App\Notifications\ReconciliationStatusChanged;
use Illuminate\Support\Facades\Notification;

function balancedReconciliation(ReconciliationStatus $status = ReconciliationStatus::Draft): Reconciliation
{
    return Reconciliation::factory()->for(BankAccount::factory())->create([
        'status' => $status,
        'unadjusted_bank_balance' => 1000,
        'unadjusted_book_balance' => 1000,
        'adjusted_bank_balance' => 1000,
        'adjusted_book_balance' => 1000,
        'difference' => 0,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);
}

it('runs the full draft to certified workflow with the right roles', function () {
    Notification::fake();
    User::factory()->role(UserRole::BudgetOfficer)->create();

    $analyst = actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = balancedReconciliation();

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/submit")
        ->assertOk()->assertJsonPath('data.status', 'for_review');
    Notification::assertSentTo(User::where('role', UserRole::BudgetOfficer->value)->first(), ReconciliationStatusChanged::class);

    $this->actingAs(User::factory()->role(UserRole::BudgetOfficer)->create());
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/certify")
        ->assertOk()->assertJsonPath('data.status', 'certified');

    // Certified is final: nothing can move it on, and the old lock endpoint is gone.
    $this->actingAs($analyst);
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/lock")->assertNotFound();
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/submit")->assertStatus(422);
    $this->actingAs(User::factory()->role(UserRole::BudgetOfficer)->create());
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/return", ['remarks' => 'Too late.'])->assertStatus(422);
});

it('blocks submitting an unbalanced reconciliation', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = balancedReconciliation();
    $reconciliation->update(['difference' => 250, 'adjusted_bank_balance' => 1250]);
    $reconciliation->reconcilingItems()->create([
        'side' => 'bank', 'category' => 'error_understating_bank', 'operation' => 'add',
        'amount' => 250, 'is_auto_generated' => false,
    ]);

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/submit")
        ->assertStatus(422)->assertJsonValidationErrors('status');
});

it('forbids a financial analyst from certifying', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = balancedReconciliation(ReconciliationStatus::ForReview);

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/certify")->assertForbidden();
});

it('returns a reconciliation for revision with remarks', function () {
    Notification::fake();
    $analyst = User::factory()->role(UserRole::FinancialAnalyst)->create();
    $reconciliation = balancedReconciliation(ReconciliationStatus::ForReview);
    $reconciliation->update(['prepared_by' => $analyst->id]);

    $this->actingAs(User::factory()->role(UserRole::BudgetOfficer)->create());
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/return", ['remarks' => 'Bank charge is missing.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'returned')
        ->assertJsonPath('data.review_remarks', 'Bank charge is missing.');

    Notification::assertSentTo($analyst, ReconciliationStatusChanged::class);
});

it('rolls a certified reconciliation forward to the next month', function () {
    $analyst = actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = balancedReconciliation(ReconciliationStatus::Certified);
    $reconciliation->update(['adjusted_bank_balance' => 987654.32, 'adjusted_book_balance' => 987654.32]);

    $response = $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/roll-forward")->assertCreated();

    $response->assertJsonPath('data.period_start', '2026-08-01')
        ->assertJsonPath('data.period_end', '2026-08-31')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.unadjusted_bank_balance', 987654.32)
        ->assertJsonPath('data.unadjusted_book_balance', 987654.32);
});

it('will not roll forward twice', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = balancedReconciliation(ReconciliationStatus::Certified);

    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/roll-forward")->assertCreated();
    $this->postJson("/api/v1/reconciliations/{$reconciliation->id}/roll-forward")->assertStatus(422);
});
