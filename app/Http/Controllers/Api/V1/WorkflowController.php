<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnReconciliationRequest;
use App\Http\Resources\ReconciliationResource;
use App\Models\Reconciliation;
use App\Services\Workflow\PeriodRollForwardService;
use App\Services\Workflow\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowService $workflow,
        private readonly PeriodRollForwardService $rollForward,
    ) {}

    public function submit(Request $request, Reconciliation $reconciliation): ReconciliationResource
    {
        return $this->show($this->workflow->submit($reconciliation, $request->user()));
    }

    public function certify(Request $request, Reconciliation $reconciliation): ReconciliationResource
    {
        return $this->show($this->workflow->certify($reconciliation, $request->user()));
    }

    public function returnForRevision(ReturnReconciliationRequest $request, Reconciliation $reconciliation): ReconciliationResource
    {
        return $this->show(
            $this->workflow->returnForRevision($reconciliation, $request->user(), $request->string('remarks')->value())
        );
    }

    public function rollForward(Request $request, Reconciliation $reconciliation): JsonResponse
    {
        $next = $this->rollForward->createNextPeriod($reconciliation, $request->user());

        return ReconciliationResource::make($next->load('bankAccount'))->response()->setStatusCode(201);
    }

    private function show(Reconciliation $reconciliation): ReconciliationResource
    {
        return ReconciliationResource::make(
            $reconciliation->load(['bankAccount', 'preparedBy', 'reviewedBy'])
        );
    }
}
