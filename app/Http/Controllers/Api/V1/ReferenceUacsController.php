<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReferenceUacsRequest;
use App\Http\Resources\ReferenceUacsResource;
use App\Models\ReferenceUacs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReferenceUacsController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $codes = ReferenceUacs::query()
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->paginate(min($request->integer('per_page', 10), 100));

        return ReferenceUacsResource::collection($codes);
    }

    public function store(StoreReferenceUacsRequest $request): JsonResponse
    {
        $code = ReferenceUacs::create($request->validated());

        return ReferenceUacsResource::make($code)->response()->setStatusCode(201);
    }

    public function show(ReferenceUacs $referenceUac): ReferenceUacsResource
    {
        return ReferenceUacsResource::make($referenceUac);
    }

    public function update(StoreReferenceUacsRequest $request, ReferenceUacs $referenceUac): ReferenceUacsResource
    {
        $referenceUac->update($request->validated());

        return ReferenceUacsResource::make($referenceUac);
    }

    public function destroy(ReferenceUacs $referenceUac): JsonResponse
    {
        $referenceUac->delete();

        return response()->json(null, 204);
    }

    /**
     * Delete several codes at once. Checks keep the code they were saved with,
     * so removing a code from this list never breaks a record.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $codes = ReferenceUacs::query()->whereIn('id', $data['ids'])->get();
        $codes->each->delete();

        return response()->json([
            'deleted' => $codes->count(),
            'skipped' => collect($data['ids'])
                ->diff($codes->pluck('id'))
                ->map(fn ($id) => ['id' => $id, 'label' => null, 'reason' => 'Already removed.'])
                ->values(),
        ]);
    }
}
