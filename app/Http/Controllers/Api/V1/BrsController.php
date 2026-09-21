<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SignatoryResource;
use App\Models\Reconciliation;
use App\Services\Reconciliation\BrsCalculator;
use Illuminate\Http\JsonResponse;

class BrsController extends Controller
{
    public function __construct(private readonly BrsCalculator $calculator) {}

    /**
     * The computed Bank Reconciliation Statement (COA Appendix 81) payload.
     */
    public function show(Reconciliation $reconciliation): JsonResponse
    {
        $reconciliation->load('bankAccount.signatories');
        $brs = $this->calculator->compute($reconciliation);

        return response()->json([
            'reconciliation' => [
                'id' => $reconciliation->id,
                'entity_name' => $reconciliation->bankAccount->entity_name,
                'fund_cluster' => $reconciliation->bankAccount->fund_cluster,
                'account_number' => $reconciliation->bankAccount->account_number,
                'statement_label' => $reconciliation->statement_label,
                'period_start' => $reconciliation->period_start?->toDateString(),
                'period_end' => $reconciliation->period_end?->toDateString(),
                'status' => $reconciliation->status->value,
            ],
            'brs' => $brs,
            'signatories' => SignatoryResource::collection($reconciliation->bankAccount->signatories),
        ]);
    }
}
