<?php

namespace App\Services\Workflow;

use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\Reconciliation;
use App\Models\User;
use App\Notifications\ReconciliationStatusChanged;
use App\Services\Reconciliation\BrsCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function __construct(private readonly BrsCalculator $calculator) {}

    public function submit(Reconciliation $reconciliation, User $actor): Reconciliation
    {
        $this->authorise($actor, [UserRole::FinancialAnalyst, UserRole::Admin]);
        $this->assertTransition($reconciliation, ReconciliationStatus::ForReview);

        $brs = $this->calculator->compute($reconciliation);
        if (! $brs['is_balanced']) {
            throw ValidationException::withMessages([
                'status' => ['The statement is not balanced yet (difference '.number_format($brs['difference'], 2).'). Resolve it before submitting.'],
            ]);
        }

        $reconciliation->update([
            'status' => ReconciliationStatus::ForReview,
            'prepared_by' => $actor->id,
            'prepared_at' => now(),
            'review_remarks' => null,
        ]);

        $this->notifyRoles(
            [UserRole::BudgetOfficer, UserRole::Admin],
            $reconciliation,
            "{$actor->name} submitted a reconciliation for review."
        );

        return $reconciliation;
    }

    public function certify(Reconciliation $reconciliation, User $actor): Reconciliation
    {
        $this->authorise($actor, [UserRole::BudgetOfficer, UserRole::Admin]);
        $this->assertTransition($reconciliation, ReconciliationStatus::Certified);

        $reconciliation->update([
            'status' => ReconciliationStatus::Certified,
            'reviewed_by' => $actor->id,
            'certified_at' => now(),
        ]);

        $this->notifyUser($reconciliation->preparedBy, $reconciliation, "{$actor->name} certified your reconciliation.");

        return $reconciliation;
    }

    public function returnForRevision(Reconciliation $reconciliation, User $actor, string $remarks): Reconciliation
    {
        $this->authorise($actor, [UserRole::BudgetOfficer, UserRole::Admin]);
        $this->assertTransition($reconciliation, ReconciliationStatus::Returned);

        $reconciliation->update([
            'status' => ReconciliationStatus::Returned,
            'reviewed_by' => $actor->id,
            'review_remarks' => $remarks,
        ]);

        $this->notifyUser($reconciliation->preparedBy, $reconciliation, "{$actor->name} returned your reconciliation: {$remarks}");

        return $reconciliation;
    }

    /**
     * @param  list<UserRole>  $roles
     */
    private function authorise(User $actor, array $roles): void
    {
        if (! $actor->hasRole(...$roles)) {
            throw new AuthorizationException('Your role cannot perform this workflow action.');
        }
    }

    private function assertTransition(Reconciliation $reconciliation, ReconciliationStatus $target): void
    {
        if (! $reconciliation->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => ["A {$reconciliation->status->label()} reconciliation cannot move to {$target->label()}."],
            ]);
        }
    }

    /**
     * @param  list<UserRole>  $roles
     */
    private function notifyRoles(array $roles, Reconciliation $reconciliation, string $message): void
    {
        $users = User::query()
            ->whereIn('role', array_map(fn (UserRole $r) => $r->value, $roles))
            ->where('is_active', true)
            ->get();

        Notification::send($users, new ReconciliationStatusChanged($reconciliation, $message));
    }

    private function notifyUser(?User $user, Reconciliation $reconciliation, string $message): void
    {
        $user?->notify(new ReconciliationStatusChanged($reconciliation, $message));
    }
}
