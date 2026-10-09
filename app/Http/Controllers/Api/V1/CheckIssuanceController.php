<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckIssuanceRequest;
use App\Http\Resources\CheckIssuanceResource;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CheckIssuance;
use App\Models\ImportBatch;
use App\Models\Reconciliation;
use App\Models\ReferenceUacs;
use App\Services\Reconciliation\CheckRegisterService;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckIssuanceController extends Controller
{
    /** Most checks one bulk request may carry. */
    private const BULK_LIMIT = 500;

    public function __construct(
        private readonly ReconciliationEngine $engine,
        private readonly CheckRegisterService $register,
    ) {}

    /**
     * The Checks Register: every check issued across all bank accounts, with
     * filters, paging and totals. Imported and hand-entered checks live side
     * by side here.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'bank_account_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'in:'.implode(',', array_column(CheckStatus::cases(), 'value'))],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $base = CheckIssuance::query()->filtered($request->only(['bank_account_id', 'status', 'search', 'date_from', 'date_to']));

        $live = (clone $base)->where('status', '!=', CheckStatus::Cancelled->value);
        $open = (clone $base)->whereIn('status', [CheckStatus::Outstanding->value, CheckStatus::Stale->value]);

        $summary = [
            'count' => (clone $base)->count(),
            'amount' => round((float) (clone $live)->sum('amount'), 2),
            'outstanding_count' => (clone $open)->count(),
            'outstanding_amount' => round((float) (clone $open)->sum('amount'), 2),
        ];

        $checks = (clone $base)
            ->with([
                'bankAccount:id,bank_short_name,bank_name,account_number,fund_cluster',
                'creator:id,name',
                'updater:id,name',
                'reconciliation:id,status,period_start,period_end,statement_label',
            ])
            ->orderByDesc('check_date')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 10), 100));

        return response()->json([
            'data' => CheckIssuanceResource::collection($checks->getCollection())->resolve(),
            'meta' => [
                'current_page' => $checks->currentPage(),
                'last_page' => $checks->lastPage(),
                'per_page' => $checks->perPage(),
                'from' => $checks->firstItem(),
                'to' => $checks->lastItem(),
                'total' => $checks->total(),
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * Just enough of each active bank account to pick one in the register's
     * filter and "add check" form — open to every role that can see the
     * register, without exposing the rest of master data.
     */
    public function bankAccounts(): JsonResponse
    {
        $accounts = BankAccount::query()
            ->where('is_active', true)
            ->orderBy('bank_short_name')
            ->orderBy('account_number')
            ->get(['id', 'bank_name', 'bank_short_name', 'account_number', 'account_name', 'fund_cluster', 'entity_name']);

        return response()->json([
            'data' => $accounts->map(fn (BankAccount $a) => [
                'id' => $a->id,
                'name' => ($a->bank_short_name ?: $a->bank_name).' · '.$a->account_number,
                'bank_name' => $a->bank_name,
                'bank_short_name' => $a->bank_short_name,
                'account_number' => $a->account_number,
                'account_name' => $a->account_name,
                'fund_cluster' => $a->fund_cluster,
                'entity_name' => $a->entity_name,
            ])->values(),
        ]);
    }

    /**
     * The active UACS object codes, for the searchable code picker on the
     * check form — open to everyone who can record checks, without exposing
     * the master-data screen.
     */
    public function uacsCodes(): JsonResponse
    {
        return response()->json([
            'data' => ReferenceUacs::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'description'])
                ->map(fn (ReferenceUacs $u) => ['code' => $u->code, 'description' => $u->description])
                ->values(),
        ]);
    }

    /**
     * Checks recorded for one reconciliation's period.
     */
    public function index(Reconciliation $reconciliation): AnonymousResourceCollection
    {
        return CheckIssuanceResource::collection(
            $reconciliation->checkIssuances()
                ->with(['bankAccount:id,bank_short_name,bank_name,account_number,fund_cluster', 'creator:id,name', 'updater:id,name'])
                ->orderBy('check_date')
                ->orderBy('serial_no')
                ->get()
        );
    }

    /**
     * Record a check issued — typed into the register, as an alternative (or
     * addition) to importing the Report of Checks Issued file.
     */
    public function store(StoreCheckIssuanceRequest $request): JsonResponse
    {
        $account = BankAccount::query()->findOrFail($request->integer('bank_account_id'));
        $period = $this->register->periodFor($account->id, $request->input('check_date'));
        $this->register->assertPeriodOpen($period);

        $check = $account->checkIssuances()->create(
            $request->safe()->except('bank_account_id') + [
                'reconciliation_id' => $period?->id,
                'status' => CheckStatus::Outstanding,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]
        );

        $this->audit($request, $check, 'created', "Check {$check->serial_no} recorded in the Checks Register");
        $this->register->refreshOpenPeriods($account->id, $check->check_date?->toDateString());

        return CheckIssuanceResource::make($check->load(['bankAccount', 'creator', 'updater']))->response()->setStatusCode(201);
    }

    /**
     * Correct a check while it is still outstanding — e.g. a typo in the
     * amount or payee.
     */
    public function update(StoreCheckIssuanceRequest $request, CheckIssuance $checkIssuance): CheckIssuanceResource
    {
        $this->assertEditable($checkIssuance->reconciliation);
        $this->assertOutstanding($checkIssuance);

        $oldDate = $checkIssuance->check_date?->toDateString();
        $newDate = $request->input('check_date');

        if ($checkIssuance->import_batch_id === null) {
            $this->register->assertPeriodOpen($this->register->periodFor($checkIssuance->bank_account_id, $newDate));
        }

        $checkIssuance->update($request->validated() + ['updated_by' => $request->user()->id]);

        if ($checkIssuance->import_batch_id === null) {
            $this->register->assignPeriod($checkIssuance->refresh());
        }

        $this->audit($request, $checkIssuance, 'updated', "Check {$checkIssuance->serial_no} details updated");
        $this->register->refreshOpenPeriods($checkIssuance->bank_account_id, $this->earlier($oldDate, $newDate));

        return CheckIssuanceResource::make($checkIssuance->fresh(['bankAccount', 'creator', 'updater']));
    }

    /**
     * Remove a check that hasn't cleared — typed in, or imported (say a row
     * that shouldn't have been in the file). Cleared and cancelled checks stay,
     * for the audit trail.
     */
    public function destroy(Request $request, CheckIssuance $checkIssuance): JsonResponse
    {
        $this->assertEditable($checkIssuance->reconciliation);
        $this->assertOutstanding($checkIssuance);

        $this->removeCheck($request, $checkIssuance);

        $this->register->refreshOpenPeriods($checkIssuance->bank_account_id, $checkIssuance->check_date?->toDateString());

        return response()->json(null, 204);
    }

    /**
     * Remove many checks at once. Each one follows the same rules as a single
     * removal — outstanding only, in a period that can still change — and any
     * that don't qualify are skipped and reported, never half-removed.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $checks = CheckIssuance::query()->with('reconciliation')->whereIn('id', $data['ids'])->get()->keyBy('id');

        $deleted = 0;
        $skipped = [];
        // Earliest removed check date per bank account (null = unknown, refresh everything).
        $earliest = [];

        DB::transaction(function () use ($request, $data, $checks, &$deleted, &$skipped, &$earliest): void {
            foreach ($data['ids'] as $id) {
                $check = $checks->get($id);

                if ($check === null) {
                    $skipped[] = ['id' => $id, 'serial_no' => null, 'reason' => 'Already removed.'];

                    continue;
                }

                if ($check->status !== CheckStatus::Outstanding) {
                    $skipped[] = ['id' => $id, 'serial_no' => $check->serial_no, 'reason' => 'Only outstanding checks can be removed.'];

                    continue;
                }

                if ($lockedReason = $check->reconciliation?->checksLockedReason()) {
                    $skipped[] = ['id' => $id, 'serial_no' => $check->serial_no, 'reason' => $lockedReason];

                    continue;
                }

                $this->removeCheck($request, $check);
                $deleted++;

                $date = $check->check_date?->toDateString();
                $earliest[$check->bank_account_id] = array_key_exists($check->bank_account_id, $earliest)
                    ? $this->earlier($earliest[$check->bank_account_id], $date)
                    : $date;
            }
        });

        foreach ($earliest as $bankAccountId => $fromDate) {
            $this->register->refreshOpenPeriods($bankAccountId, $fromDate);
        }

        return response()->json(['deleted' => $deleted, 'skipped' => $skipped]);
    }

    /** Audit, delete and keep the import's "rows imported" figure honest. */
    private function removeCheck(Request $request, CheckIssuance $checkIssuance): void
    {
        $this->audit($request, $checkIssuance, 'deleted', "Check {$checkIssuance->serial_no} removed");

        $checkIssuance->delete();

        if ($checkIssuance->import_batch_id !== null) {
            ImportBatch::query()->whereKey($checkIssuance->import_batch_id)->where('imported_count', '>', 0)->decrement('imported_count');
        }
    }

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

        $this->assertEditable($checkIssuance->reconciliation);

        $checkIssuance->update([
            'status' => CheckStatus::Cancelled,
            'cancelled_reason' => $data['reason'],
            'updated_by' => $request->user()->id,
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

        if ($checkIssuance->reconciliation !== null) {
            $this->engine->refresh($checkIssuance->reconciliation);
        }
        $this->register->refreshOpenPeriods($checkIssuance->bank_account_id, $checkIssuance->check_date?->toDateString());

        return CheckIssuanceResource::make($checkIssuance->fresh());
    }

    private function audit(Request $request, CheckIssuance $check, string $action, string $description): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'action' => $action,
            'auditable_type' => $check->getMorphClass(),
            'auditable_id' => $check->id,
            'description' => $description,
            'ip_address' => $request->ip(),
        ]);
    }

    private function earlier(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return null;
        }

        return min($a, $b);
    }

    private function assertOutstanding(CheckIssuance $checkIssuance): void
    {
        if ($checkIssuance->status !== CheckStatus::Outstanding) {
            throw ValidationException::withMessages([
                'status' => ['Only an outstanding check can be edited or removed. Cleared or cancelled checks are kept for the audit trail.'],
            ]);
        }
    }

    /**
     * A check not yet tied to any period is always editable; one tied to a
     * period follows that period's status.
     */
    private function assertEditable(?Reconciliation $reconciliation): void
    {
        if ($reason = $reconciliation?->checksLockedReason()) {
            throw ValidationException::withMessages(['status' => [$reason]]);
        }
    }
}
