<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BankAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = BankAccount::query()
            ->withCount('signatories')
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('bank_short_name')
            ->orderBy('account_number')
            ->get();

        return BankAccountResource::collection($accounts);
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $payload = $request->safe()->except(['signatories']);
        $signatories = $request->validated('signatories');

        $account = DB::transaction(function () use ($payload, $signatories) {
            $account = BankAccount::create($payload);

            foreach (array_values($signatories) as $index => $signatory) {
                $account->signatories()->create([
                    'block' => $signatory['block'],
                    'name' => $signatory['name'],
                    'designation' => $signatory['designation'],
                    'sort_order' => $index + 1,
                ]);
            }

            return $account->load('signatories')->loadCount('signatories');
        });

        return BankAccountResource::make($account)->response()->setStatusCode(201);
    }

    public function show(BankAccount $bankAccount): BankAccountResource
    {
        return BankAccountResource::make($bankAccount->load('signatories'));
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): BankAccountResource
    {
        $bankAccount->update($request->safe()->except(['signatories']));

        return BankAccountResource::make($bankAccount->fresh(['signatories'])->loadCount('signatories'));
    }

    public function destroy(BankAccount $bankAccount): JsonResponse
    {
        $bankAccount->delete();

        return response()->json(null, 204);
    }
}
