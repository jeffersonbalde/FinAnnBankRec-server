<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Models\User;
use App\Services\Imports\ImportManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ImportBatchController extends Controller
{
    public function __construct(private readonly ImportManager $imports) {}

    /**
     * Upload + parse a file, returning a preview batch (not yet committed).
     */
    public function store(StoreImportBatchRequest $request, Reconciliation $reconciliation): JsonResponse
    {
        $this->assertEditable($reconciliation);

        $type = ImportType::from($request->string('type')->value());
        $this->assertMayImport($request->user(), $type);

        $batch = $this->imports->parseUpload($reconciliation, $type, $request->file('file'), $request->user()->id);

        return ImportBatchResource::make($batch)->response()->setStatusCode(201);
    }

    /**
     * Persist a parsed batch's rows into the domain tables.
     */
    public function commit(ImportBatch $importBatch): ImportBatchResource
    {
        $this->assertEditable($importBatch->reconciliation);
        $this->assertMayImport(request()->user(), $importBatch->type);

        if ($importBatch->status === 'committed') {
            throw ValidationException::withMessages(['import' => ['This import is already committed.']]);
        }

        return ImportBatchResource::make($this->imports->commit($importBatch));
    }

    public function destroy(ImportBatch $importBatch): JsonResponse
    {
        $this->assertEditable($importBatch->reconciliation);
        $this->assertMayImport(request()->user(), $importBatch->type);

        $this->imports->discard($importBatch);

        return response()->json(null, 204);
    }

    /**
     * The Disbursing Officer may only touch the Report of Checks Issued (their
     * own document); everything else is the Financial Analyst's / Admin's.
     */
    private function assertMayImport(User $user, ImportType $type): void
    {
        if ($user->hasRole(UserRole::FinancialAnalyst, UserRole::Admin)) {
            return;
        }

        if ($user->hasRole(UserRole::DisbursingOfficer) && $type === ImportType::Rci) {
            return;
        }

        throw new AuthorizationException('Your role cannot import this file type.');
    }

    private function assertEditable(?Reconciliation $reconciliation): void
    {
        if ($reconciliation === null || ! $reconciliation->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['This reconciliation can no longer be changed.'],
            ]);
        }
    }
}
