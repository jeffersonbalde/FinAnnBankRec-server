<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotificationRetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A person's own notifications. Everything here only ever touches the signed-in
 * user's notifications.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationRetentionService $retention) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in(['all', 'unread', 'read'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $this->retention->runIfDue();

        $user = $request->user();

        $notifications = $user->notifications()
            ->when($request->query('status') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($request->query('status') === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->latest()
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'total' => $user->notifications()->count(),
            'data' => $notifications->getCollection()->map(fn ($n) => [
                'id' => $n->id,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
                'data' => $n->data,
            ])->values(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
                'total' => $notifications->total(),
            ],
            'settings' => $this->settings($request),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->whereKey($notification)->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['message' => 'ok']);
    }

    /** Delete the chosen notifications. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['uuid', 'distinct'],
        ]);

        $deleted = $request->user()->notifications()->whereIn('id', $data['ids'])->delete();

        return response()->json(['deleted' => $deleted]);
    }

    /** Delete in one go: all that are read, everything up to a date, or all of them. */
    public function clear(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['read', 'before', 'all'])],
            'before' => ['required_if:mode,before', 'nullable', 'date'],
        ]);

        $deleted = $request->user()->notifications()
            ->when($data['mode'] === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($data['mode'] === 'before', fn ($q) => $q->whereDate('created_at', '<=', $data['before']))
            ->delete();

        return response()->json(['deleted' => $deleted]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'retention_days' => ['present', 'nullable', 'integer', Rule::in(NotificationRetentionService::OPTIONS)],
        ]);

        $user = $request->user();
        $this->retention->setRetentionDays($user, $data['retention_days']);

        // Apply it right away rather than waiting for the next scheduled run.
        $deleted = $this->retention->clearOldFor($user->refresh());

        return response()->json($this->settings($request) + ['deleted' => $deleted]);
    }

    /** @return array{retention_days: ?int, options: list<int>} */
    private function settings(Request $request): array
    {
        return [
            'retention_days' => $request->user()->notification_retention_days,
            'options' => NotificationRetentionService::OPTIONS,
        ];
    }
}
