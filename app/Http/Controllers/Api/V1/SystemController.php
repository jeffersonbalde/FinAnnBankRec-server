<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\BackupScheduleService;
use App\Services\DatabaseBackupService;
use App\Services\SampleDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemController extends Controller
{
    public function status(DatabaseBackupService $backups, BackupScheduleService $schedule): JsonResponse
    {
        // Catch up a due scheduled backup when admin opens this page (helps without OS cron).
        $schedule->runIfDue($backups);

        $files = $backups->listFiles();
        $sqlFiles = array_values(array_filter($files, fn ($f) => $f['format'] === 'sql'));
        $latest = $sqlFiles[0] ?? ($files[0] ?? null);
        $settings = $schedule->get();
        $summary = $schedule->summary();

        return response()->json([
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'database' => config('database.default'),
            'users_active' => User::where('is_active', true)->count(),
            'users_inactive' => User::where('is_active', false)->count(),
            'timestamp' => now()->toIso8601String(),
            'backup' => [
                'directory' => $backups->directory(),
                'count' => count($files),
                'sql_count' => count($sqlFiles),
                'latest' => $latest,
                'schedule' => array_merge($settings, $summary),
                'schedule_enabled' => $settings['enabled'],
                'schedule_time' => $settings['time'],
                'retention_days' => $settings['retention_days'],
            ],
        ]);
    }

    public function indexBackups(DatabaseBackupService $backups, BackupScheduleService $schedule): JsonResponse
    {
        $settings = $schedule->get();
        $summary = $schedule->summary();

        return response()->json([
            'data' => $backups->listFiles(),
            'schedule' => array_merge($settings, $summary),
            'schedule_enabled' => $settings['enabled'],
            'schedule_time' => $settings['time'],
            'retention_days' => $settings['retention_days'],
        ]);
    }

    public function showSchedule(BackupScheduleService $schedule): JsonResponse
    {
        $settings = $schedule->get();
        $summary = $schedule->summary();

        return response()->json(array_merge($settings, $summary));
    }

    public function updateSchedule(Request $request, BackupScheduleService $schedule): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'integer', 'between:0,6', 'required_if:frequency,weekly'],
            'retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $saved = $schedule->save($data);
        $summary = $schedule->summary();

        $this->writeAudit($request, 'system.backup_schedule_updated', $saved);

        return response()->json([
            'message' => 'Backup schedule saved.',
            'schedule' => array_merge($saved, $summary),
        ]);
    }

    public function createBackup(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        $created = $backups->writeToDisk();

        $this->writeAudit($request, 'system.backup_created', [
            'name' => $created['name'],
            'size' => $created['size'],
            'format' => 'sql',
        ]);

        return response()->json([
            'message' => 'Backup created successfully.',
            'backup' => [
                'name' => $created['name'],
                'format' => 'sql',
                'size' => $created['size'],
                'generated_at' => $created['generated_at'],
                'json_name' => $created['json_name'] ?? null,
            ],
        ], 201);
    }

    public function downloadBackup(string $filename, DatabaseBackupService $backups): BinaryFileResponse
    {
        try {
            $path = $backups->resolvePath($filename);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        $mime = str_ends_with(strtolower($filename), '.sql')
            ? 'application/sql'
            : 'application/json';

        return response()->download($path, $filename, [
            'Content-Type' => $mime,
        ]);
    }

    /**
     * Create a fresh SQL backup on disk and stream it for download.
     */
    public function downloadFreshBackup(DatabaseBackupService $backups): StreamedResponse
    {
        $created = $backups->writeToDisk();
        $path = $created['path'];
        $filename = $created['name'];

        return response()->streamDownload(function () use ($path) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }
            fpassthru($handle);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'application/sql',
        ]);
    }

    public function destroyBackup(Request $request, string $filename, DatabaseBackupService $backups): JsonResponse
    {
        try {
            $backups->deleteFile($filename);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $this->writeAudit($request, 'system.backup_deleted', ['name' => $filename]);

        return response()->json(['message' => 'Backup deleted.']);
    }

    /**
     * Wipe reconciliation activity (sample or real); master data stays.
     */
    public function clearActivityData(Request $request, SampleDataService $sampleData): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'in:CLEAR']]);

        $result = $sampleData->clear();

        $this->writeAudit($request, 'system.activity_data_cleared', [
            'backup' => $result['backup'],
            'deleted' => $result['deleted'],
        ]);

        return response()->json([
            'message' => 'All reconciliation data was cleared. Users, bank accounts and UACS codes were kept.',
            'backup' => $result['backup'],
            'deleted' => $result['deleted'],
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        $this->writeAudit($request, 'user.password_changed', [], User::class, $user->id);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function writeAudit(
        Request $request,
        string $action,
        array $changes = [],
        ?string $auditableType = null,
        mixed $auditableId = null,
    ): void {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'auditable_type' => $auditableType ?? 'system',
            'auditable_id' => $auditableId ?? 0,
            'description' => $action,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
