<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ActivityRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The administrator's Activity Log: every person's footprints, including
 * entries they cleared from their own list.
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly ActivityRetentionService $retention) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action' => ['sometimes', 'nullable', 'string', 'max:50'],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $this->retention->runIfDue();

        $logs = AuditLog::query()
            ->filtered($request->only(['search', 'action', 'type', 'user_id', 'date_from', 'date_to']))
            ->latest()
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => ActivityResource::collection($logs->getCollection())->resolve(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ],
            'summary' => [
                'total' => AuditLog::query()->count(),
                'today' => AuditLog::query()->where('created_at', '>=', now()->startOfDay())->count(),
                'people' => AuditLog::query()->whereNotNull('user_id')->distinct()->count('user_id'),
            ],
        ]);
    }

    /** Everyone who appears in the log, with their photo and role, for the "choose a user" filter. */
    public function people(): JsonResponse
    {
        $logged = AuditLog::query()
            ->whereNotNull('user_id')
            ->select('user_id')
            ->selectRaw('max(user_name) as name')
            ->groupBy('user_id')
            ->get();

        $users = User::query()->whereIn('id', $logged->pluck('user_id'))->get()->keyBy('id');

        $people = $logged
            ->map(fn ($row) => [
                'id' => $row->user_id,
                // The current name if the account still exists, else the name written down at the time.
                'name' => $users[$row->user_id]->name ?? $row->name ?? 'User #'.$row->user_id,
                'avatar_url' => $users[$row->user_id]->avatar_url ?? null,
                'role_label' => $users[$row->user_id]?->role?->label(),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json(['data' => $people]);
    }

    /**
     * Permanently delete entries: chosen ones, everything up to a date, one
     * person's, or all. This removes the audit copy for good, so the action is
     * itself recorded afterwards.
     */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['ids', 'before', 'user', 'all'])],
            'ids' => ['required_if:mode,ids', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'before' => ['required_if:mode,before', 'nullable', 'date'],
            'user_id' => ['required_if:mode,user', 'nullable', 'integer'],
            'confirm' => ['required_if:mode,all', 'nullable', 'in:CLEAR'],
        ]);

        $deleted = AuditLog::query()
            ->when($data['mode'] === 'ids', fn ($q) => $q->whereIn('id', $data['ids']))
            ->when($data['mode'] === 'before', fn ($q) => $q->whereDate('created_at', '<=', $data['before']))
            ->when($data['mode'] === 'user', fn ($q) => $q->where('user_id', $data['user_id']))
            ->delete();

        if ($deleted > 0) {
            AuditLog::record($request->user(), 'system.audit_purged', "Permanently deleted {$deleted} activity ".($deleted === 1 ? 'entry' : 'entries'), null, [
                'mode' => $data['mode'],
                'count' => $deleted,
            ]);
        }

        return response()->json(['deleted' => $deleted]);
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->settingsPayload());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'retention_days' => ['present', 'nullable', 'integer', Rule::in(ActivityRetentionService::SYSTEM_OPTIONS)],
        ]);

        $this->retention->setSystemRetentionDays($data['retention_days']);

        $purged = $data['retention_days'] === null ? 0 : $this->retention->run()['purged'];

        AuditLog::record($request->user(), 'system.audit_retention_updated', $data['retention_days'] === null
            ? 'Turned off automatic deletion of old activity'
            : "Set old activity to be deleted automatically after {$data['retention_days']} days", null, ['retention_days' => $data['retention_days']]);

        return response()->json($this->settingsPayload() + ['purged' => $purged]);
    }

    /** @return array{retention_days: ?int, options: list<int>, last_run_at: ?string} */
    private function settingsPayload(): array
    {
        return [
            'retention_days' => $this->retention->systemRetentionDays(),
            'options' => ActivityRetentionService::SYSTEM_OPTIONS,
            'last_run_at' => $this->retention->lastRunAt(),
        ];
    }
}
