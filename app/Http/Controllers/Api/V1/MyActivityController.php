<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\AuditLog;
use App\Services\ActivityRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "My Activity": a person's own footprints in the system. They can clear them
 * from their list (the audit copy stays for the administrator) or set them to
 * clear themselves after a while.
 */
class MyActivityController extends Controller
{
    public function __construct(private readonly ActivityRetentionService $retention) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action' => ['sometimes', 'nullable', 'string', 'max:50'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $this->retention->runIfDue();

        $user = $request->user();
        $own = fn () => AuditLog::query()->where('user_id', $user->id)->whereNull('hidden_at');

        $logs = $own()
            ->filtered($request->only(['search', 'action', 'date_from', 'date_to']))
            ->latest()
            ->latest('id')
            ->paginate($request->integer('per_page', 10));

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
                'total' => $own()->count(),
                'today' => $own()->where('created_at', '>=', now()->startOfDay())->count(),
                'this_week' => $own()->where('created_at', '>=', now()->startOfWeek())->count(),
            ],
            'settings' => $this->settings($request),
        ]);
    }

    /**
     * Remove entries from the person's own list: chosen ones, everything up to a
     * date, or all of it. Only ever their own.
     */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['ids', 'before', 'all'])],
            'ids' => ['required_if:mode,ids', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'before' => ['required_if:mode,before', 'nullable', 'date'],
        ]);

        $user = $request->user();

        $query = AuditLog::query()->where('user_id', $user->id)->whereNull('hidden_at')
            ->when($data['mode'] === 'ids', fn ($q) => $q->whereIn('id', $data['ids']))
            ->when($data['mode'] === 'before', fn ($q) => $q->whereDate('created_at', '<=', $data['before']));

        $cleared = $query->update(['hidden_at' => now()]);

        if ($cleared > 0) {
            // Leave a trace that it was done, so the administrator can see history was cleared.
            AuditLog::record($user, 'system.activity_cleared', "Cleared {$cleared} ".($cleared === 1 ? 'entry' : 'entries').' from your history', null, [
                'mode' => $data['mode'],
                'count' => $cleared,
            ]);
        }

        return response()->json(['cleared' => $cleared]);
    }

    /** @return array{retention_days: ?int, options: list<int>} */
    private function settings(Request $request): array
    {
        return [
            'retention_days' => $request->user()->activity_retention_days,
            'options' => ActivityRetentionService::USER_OPTIONS,
        ];
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'retention_days' => ['present', 'nullable', 'integer', Rule::in(ActivityRetentionService::USER_OPTIONS)],
        ]);

        $user = $request->user();
        $this->retention->setUserRetentionDays($user, $data['retention_days']);

        // Apply it right away rather than waiting for the next scheduled run.
        $cleared = $this->retention->clearOldFor($user->refresh());

        return response()->json($this->settings($request) + ['cleared' => $cleared]);
    }
}
