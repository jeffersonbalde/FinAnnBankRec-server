<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Enums\ReconciliationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReconciliationRequest;
use App\Http\Resources\ReconciliationResource;
use App\Models\CheckIssuance;
use App\Models\Reconciliation;
use App\Services\Reconciliation\CheckRegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReconciliationController extends Controller
{
    /** Most reconciliations one bulk request may carry. */
    private const BULK_LIMIT = 100;

    public function __construct(private readonly CheckRegisterService $register) {}

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

        $this->remove($reconciliation);

        return response()->json(null, 204);
    }

    /**
     * Delete several reconciliations at once. Only Draft / Returned ones can go;
     * any other is skipped and reported, never half-removed.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $found = Reconciliation::query()->with('bankAccount')->whereIn('id', $data['ids'])->get()->keyBy('id');

        $deleted = 0;
        $skipped = [];

        foreach ($data['ids'] as $id) {
            $reconciliation = $found->get($id);

            if ($reconciliation === null) {
                $skipped[] = ['id' => $id, 'label' => null, 'reason' => 'Already removed.'];

                continue;
            }

            if (! $reconciliation->status->isEditable()) {
                $skipped[] = [
                    'id' => $id,
                    'label' => $this->label($reconciliation),
                    'reason' => 'It is '.$reconciliation->status->label().' and can no longer be changed.',
                ];

                continue;
            }

            $this->remove($reconciliation);
            $deleted++;
        }

        return response()->json(['deleted' => $deleted, 'skipped' => $skipped]);
    }

    /**
     * Remove a period cleanly. Checks stay in the Checks Register (a later period
     * picks them up); checks this period's statement had cleared become outstanding
     * again; its statement rows and uploaded files go with it.
     */
    private function remove(Reconciliation $reconciliation): void
    {
        $storedPaths = $reconciliation->importBatches()->whereNotNull('stored_path')->pluck('stored_path');

        DB::transaction(function () use ($reconciliation): void {
            $transactionIds = $reconciliation->bankTransactions()->pluck('id');

            if ($transactionIds->isNotEmpty()) {
                CheckIssuance::query()
                    ->whereIn('cleared_bank_transaction_id', $transactionIds)
                    ->update([
                        'status' => CheckStatus::Outstanding->value,
                        'cleared_on' => null,
                        'cleared_bank_transaction_id' => null,
                    ]);
            }

            $reconciliation->bankTransactions()->delete();
            $reconciliation->delete();
        });

        foreach ($storedPaths as $path) {
            Storage::delete($path);
        }

        // Later open periods carry these checks over again.
        $this->register->refreshOpenPeriods($reconciliation->bank_account_id, $reconciliation->period_start?->toDateString());
    }

    private function label(Reconciliation $reconciliation): string
    {
        $account = $reconciliation->bankAccount;

        return $reconciliation->period_start?->format('M j, Y').' – '.$reconciliation->period_end?->format('M j, Y')
            .($account ? ' · '.($account->bank_short_name ?: $account->bank_name).' '.$account->account_number : '');
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
