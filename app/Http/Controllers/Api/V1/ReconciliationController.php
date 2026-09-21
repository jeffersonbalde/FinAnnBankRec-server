<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReconciliationRequest;
use App\Http\Resources\ReconciliationResource;
use App\Models\Reconciliation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ReconciliationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $reconciliations = Reconciliation::query()
            ->with('bankAccount')
            ->withCount(['checkIssuances', 'bankTransactions'])
            ->when($request->filled('bank_account_id'), fn ($q) => $q->where('bank_account_id', $request->integer('bank_account_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('statement_label', 'like', $term)
                        ->orWhere('report_no', 'like', $term)
                        ->orWhereHas('bankAccount', function ($ba) use ($term) {
                            $ba->where('bank_name', 'like', $term)
                                ->orWhere('bank_short_name', 'like', $term)
                                ->orWhere('account_number', 'like', $term)
                                ->orWhere('fund_cluster', 'like', $term);
                        });
                });
            })
            ->orderByDesc('period_end')
            ->paginate(min($request->integer('per_page', 10), 100));

        return ReconciliationResource::collection($reconciliations);
    }

    public function store(StoreReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['prepared_by'] = $request->user()->id;
        $data['status'] = ReconciliationStatus::Draft;

        $reconciliation = Reconciliation::create($data);

        return ReconciliationResource::make($reconciliation->load('bankAccount'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Reconciliation $reconciliation): ReconciliationResource
    {
        $reconciliation->load(['bankAccount.signatories', 'importBatches.uploadedBy', 'preparedBy', 'reviewedBy'])
            ->loadCount(['checkIssuances', 'bankTransactions']);

        return ReconciliationResource::make($reconciliation);
    }

    public function update(StoreReconciliationRequest $request, Reconciliation $reconciliation): ReconciliationResource
    {
        $this->assertEditable($reconciliation);

        $reconciliation->update($request->validated());

        return ReconciliationResource::make($reconciliation->load('bankAccount'));
    }

    public function destroy(Reconciliation $reconciliation): JsonResponse
    {
        $this->assertEditable($reconciliation);

        $reconciliation->delete();

        return response()->json(null, 204);
    }

    private function assertEditable(Reconciliation $reconciliation): void
    {
        if (! $reconciliation->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['This reconciliation is '.$reconciliation->status->label().' and can no longer be changed.'],
            ]);
        }
    }
}
