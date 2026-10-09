<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Enums\MatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BankTransactionResource;
use App\Http\Resources\CheckIssuanceResource;
use App\Models\AuditLog;
use App\Models\BankTransaction;
use App\Models\Reconciliation;
use App\Services\Reconciliation\BrsCalculator;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MatchController extends Controller
{
    public function __construct(
        private readonly ReconciliationEngine $engine,
        private readonly BrsCalculator $calculator,
    ) {}

    /**
     * Run the full engine (classify -> match -> items -> BRS).
     */
    public function run(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $this->assertEditable($reconciliation);

        $result = $this->engine->run($reconciliation, $request->user()->id);
        AuditLog::record($request->user(), 'updated', 'Matched checks with the bank statement for reconciliation #'.$reconciliation->id, $reconciliation);

        return response()->json([
            'match' => $result['match'],
            'brs' => $result['brs'],
        ] + $this->boardPayload($reconciliation));
    }

    /**
     * Current matching board: issued checks vs bank clearings + open flags.
     */
    public function index(Reconciliation $reconciliation): JsonResponse
    {
        return response()->json($this->boardPayload($reconciliation));
    }

    public function link(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        $reconciliation = $bankTransaction->reconciliation;
        $this->assertEditable($reconciliation);

        $data = $request->validate([
            'check_issuance_id' => ['required', 'exists:check_issuances,id'],
        ]);

        $check = $reconciliation->bankAccount->checkIssuances()->findOrFail($data['check_issuance_id']);

        $bankTransaction->update([
            'match_status' => MatchStatus::Manual,
            'matched_check_issuance_id' => $check->id,
        ]);
        $check->update([
            'status' => CheckStatus::Cleared,
            'cleared_on' => $bankTransaction->txn_date?->toDateString(),
            'cleared_bank_transaction_id' => $bankTransaction->id,
        ]);

        $this->engine->refresh($reconciliation);
        AuditLog::record($request->user(), 'updated', 'Linked check '.$check->serial_no.' to a bank transaction', $reconciliation);

        return response()->json($this->boardPayload($reconciliation->refresh()));
    }

    public function unlink(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        $reconciliation = $bankTransaction->reconciliation;
        $this->assertEditable($reconciliation);

        if ($bankTransaction->matched_check_issuance_id) {
            $reconciliation->bankAccount->checkIssuances()
                ->whereKey($bankTransaction->matched_check_issuance_id)
                ->update([
                    'status' => CheckStatus::Outstanding,
                    'cleared_on' => null,
                    'cleared_bank_transaction_id' => null,
                ]);
        }

        $bankTransaction->update([
            'match_status' => MatchStatus::Unmatched,
            'matched_check_issuance_id' => null,
        ]);

        $this->engine->refresh($reconciliation);
        AuditLog::record($request->user(), 'updated', 'Unlinked a bank transaction from its check', $reconciliation);

        return response()->json($this->boardPayload($reconciliation->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function boardPayload(Reconciliation $reconciliation): array
    {
        $checks = $reconciliation->matchingChecks()->with('reconciliation')->get();

        $clearings = $reconciliation->bankTransactions()
            ->where('is_balance_forward', false)
            ->orderBy('txn_date')
            ->get();

        return [
            'checks' => CheckIssuanceResource::collection($checks),
            'bank_transactions' => BankTransactionResource::collection($clearings),
            'flags' => $reconciliation->latestMatchRun()?->flags ?? [],
            'last_run_at' => $reconciliation->latestMatchRun()?->created_at,
        ];
    }

    private function assertEditable(?Reconciliation $reconciliation): void
    {
        if ($reconciliation === null || ! $reconciliation->status->isEditable()) {
            throw ValidationException::withMessages(['status' => ['This reconciliation can no longer be changed.']]);
        }
    }
}
