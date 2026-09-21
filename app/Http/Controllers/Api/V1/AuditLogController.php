<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->when($request->filled('type'), fn ($q) => $q->where('auditable_type', 'like', '%'.$request->string('type').'%'))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest()
            ->paginate(min($request->integer('per_page', 50), 200));

        $logs->getCollection()->transform(fn (AuditLog $log) => [
            'id' => $log->id,
            'who' => $log->user_name ?? 'System',
            'action' => $log->action,
            'entity' => class_basename($log->auditable_type).' #'.$log->auditable_id,
            'description' => $log->description,
            'changes' => $log->changes,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at,
        ]);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
