<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSignatoryRequest;
use App\Http\Resources\SignatoryResource;
use App\Models\BankAccount;
use App\Models\Signatory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SignatoryController extends Controller
{
    public function index(BankAccount $bankAccount): AnonymousResourceCollection
    {
        return SignatoryResource::collection($bankAccount->signatories);
    }

    public function store(StoreSignatoryRequest $request, BankAccount $bankAccount): JsonResponse
    {
        $signatory = $bankAccount->signatories()->create($request->validated());

        return SignatoryResource::make($signatory)->response()->setStatusCode(201);
    }

    public function update(StoreSignatoryRequest $request, Signatory $signatory): SignatoryResource
    {
        $signatory->update($request->validated());

        return SignatoryResource::make($signatory);
    }

    public function destroy(Signatory $signatory): JsonResponse
    {
        $signatory->delete();

        return response()->json(null, 204);
    }
}
