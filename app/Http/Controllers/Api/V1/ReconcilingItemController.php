<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReconcilingItemCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReconcilingItemRequest;
use App\Http\Resources\ReconcilingItemResource;
use App\Models\Reconciliation;
use App\Models\ReconcilingItem;
use App\Services\Reconciliation\BrsCalculator;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ReconcilingItemController extends Controller
{
    public function __construct(
        private readonly BrsCalculator $calculator,
        private readonly ReconciliationEngine $engine,
    ) {}

    public function index(Reconciliation $reconciliation): AnonymousResourceCollection
    {
        return ReconcilingItemResource::collection(
            $reconciliation->reconcilingItems()->orderBy('side')->orderBy('category')->get()
        );
    }

    public function store(StoreReconcilingItemRequest $request, Reconciliation $reconciliation): JsonResponse
    {
        $this->assertEditable($reconciliation);

        $category = ReconcilingItemCategory::from($request->string('category')->value());

        $item = $reconciliation->reconcilingItems()->create([
            'side' => $category->side(),
            'category' => $category,
            'operation' => $category->operation(),
            'amount' => $request->float('amount'),
            'explanatory_comment' => $request->string('explanatory_comment'),
            'schedule_no' => $request->input('schedule_no'),
            'is_auto_generated' => false,
        ]);

        $this->calculator->compute($reconciliation);

        return ReconcilingItemResource::make($item)->response()->setStatusCode(201);
    }

    public function update(StoreReconcilingItemRequest $request, ReconcilingItem $reconcilingItem): ReconcilingItemResource
    {
        $this->assertManual($reconcilingItem);
        $this->assertEditable($reconcilingItem->reconciliation);

        $category = ReconcilingItemCategory::from($request->string('category')->value());

        $reconcilingItem->update([
            'side' => $category->side(),
            'category' => $category,
            'operation' => $category->operation(),
            'amount' => $request->float('amount'),
            'explanatory_comment' => $request->string('explanatory_comment'),
            'schedule_no' => $request->input('schedule_no'),
        ]);

        $this->calculator->compute($reconcilingItem->reconciliation);

        return ReconcilingItemResource::make($reconcilingItem);
    }

    public function destroy(ReconcilingItem $reconcilingItem): JsonResponse
    {
        $this->assertManual($reconcilingItem);
        $this->assertEditable($reconcilingItem->reconciliation);

        $reconciliation = $reconcilingItem->reconciliation;
        $reconcilingItem->delete();
        $this->calculator->compute($reconciliation);

        return response()->json(null, 204);
    }

    public function regenerate(Reconciliation $reconciliation): JsonResponse
    {
        $this->assertEditable($reconciliation);

        $brs = $this->engine->refresh($reconciliation);

        return response()->json(['brs' => $brs]);
    }

    private function assertManual(ReconcilingItem $item): void
    {
        if ($item->is_auto_generated) {
            throw ValidationException::withMessages([
                'item' => ['Engine-generated items cannot be edited directly. Adjust the source data instead.'],
            ]);
        }
    }

    private function assertEditable(?Reconciliation $reconciliation): void
    {
        if ($reconciliation === null || ! $reconciliation->status->isEditable()) {
            throw ValidationException::withMessages(['status' => ['This reconciliation can no longer be changed.']]);
        }
    }
}
