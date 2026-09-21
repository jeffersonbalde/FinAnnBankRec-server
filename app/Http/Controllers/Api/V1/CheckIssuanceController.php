<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CheckIssuanceResource;
use App\Models\AuditLog;
use App\Models\CheckIssuance;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CheckIssuanceController extends Controller
{
    public function __construct(private readonly ReconciliationEngine $engine) {}

    /**
     * Void an issued check. It is added back to the book balance as a
     * "Cancelled Checks" reconciling item.
     */
    public function cancel(Request $request, CheckIssuance $checkIssuance): CheckIssuanceResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if (! in_array($checkIssuance->status, [CheckStatus::Outstanding, CheckStatus::Stale], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only an outstanding or stale check can be cancelled.'],
            ]);
        }

        $reconciliation = $checkIssuance->reconciliation;
        if ($reconciliation !== null && ! $reconciliation->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['This reconciliation can no longer be changed.'],
            ]);
        }

        $checkIssuance->update([
            'status' => CheckStatus::Cancelled,
            'cancelled_reason' => $data['reason'],
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'action' => 'updated',
            'auditable_type' => $checkIssuance->getMorphClass(),
            'auditable_id' => $checkIssuance->id,
            'description' => "Check {$checkIssuance->serial_no} cancelled",
            'changes' => ['status' => 'cancelled', 'cancelled_reason' => $data['reason']],
            'ip_address' => $request->ip(),
        ]);

        if ($reconciliation !== null) {
            $this->engine->refresh($reconciliation);
        }

        return CheckIssuanceResource::make($checkIssuance->fresh());
    }
}
