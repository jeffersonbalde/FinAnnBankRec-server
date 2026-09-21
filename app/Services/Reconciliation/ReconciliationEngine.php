<?php

namespace App\Services\Reconciliation;

use App\Models\Reconciliation;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the reconciliation pipeline: classify -> match -> generate
 * reconciling items -> compute the BRS.
 */
class ReconciliationEngine
{
    public function __construct(
        private readonly BankStatementClassifier $classifier,
        private readonly CheckMatchingService $matcher,
        private readonly ReconcilingItemGenerator $itemGenerator,
        private readonly BrsCalculator $calculator,
        private readonly StaleCheckService $staleChecks,
    ) {}

    /**
     * Full run — used after imports change.
     *
     * @return array<string, mixed>
     */
    public function run(Reconciliation $reconciliation, ?int $runBy = null): array
    {
        return DB::transaction(function () use ($reconciliation, $runBy): array {
            $this->classifier->classifyReconciliation($reconciliation);
            $this->staleChecks->sweep($reconciliation->bankAccount, $reconciliation->period_end);
            $match = $this->matcher->run($reconciliation, $runBy);
            $this->itemGenerator->generate($reconciliation);
            $brs = $this->calculator->compute($reconciliation);

            return ['match' => $match, 'brs' => $brs];
        });
    }

    /**
     * Lighter refresh — regenerate auto items + recompute the BRS. Used after a
     * manual reconciling item or an unadjusted-balance edit.
     *
     * @return array<string, mixed>
     */
    public function refresh(Reconciliation $reconciliation): array
    {
        return DB::transaction(function () use ($reconciliation): array {
            $this->itemGenerator->generate($reconciliation);

            return $this->calculator->compute($reconciliation);
        });
    }
}
