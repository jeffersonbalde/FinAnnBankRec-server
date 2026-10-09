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
                'folder' => $this->folderPayload($backups),
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
            'folder' => $this->folderPayload($backups),
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

    public function updateSchedule(Request $request, BackupScheduleService $schedule, DatabaseBackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'integer', 'between:0,6', 'required_if:frequency,weekly'],
            'retention_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $before = $schedule->get();
        $saved = $schedule->save($data);

        $timingChanged = collect(['enabled', 'frequency', 'time', 'weekday'])
            ->contains(fn (string $key) => $before[$key] !== $saved[$key]);
        if ($timingChanged) {
            $schedule->skipCurrentSlot();
        }

        $this->writeAudit($request, 'system.backup_schedule_updated', [
            'enabled' => $saved['enabled'],
            'frequency' => $saved['frequency'],
            'time' => $saved['time'],
            'retention_days' => $saved['retention_days'],
        ], description: 'Updated the automatic backup schedule');

        return response()->json([
            'message' => 'Backup schedule saved.',
            'schedule' => array_merge($schedule->get(), $schedule->summary()),
            'folder' => $this->folderPayload($backups),
        ]);
    }

    /** The folder picker: the folders (or drives) inside `path`. */
    public function browseFolders(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        $data = $request->validate(['path' => ['sometimes', 'nullable', 'string', 'max:500']]);

        try {
            return response()->json($backups->browse($data['path'] ?? null));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['path' => [$e->getMessage()]]);
        }
    }

    /**
     * The full path of a folder the user picked in their browser (the browser
     * only gives its name) — found through a marker file the page left in it.
     */
    public function locateFolder(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'marker' => ['required', 'string', 'regex:/^\.fabres-check-[a-f0-9]{8,64}$/'],
        ]);

        return response()->json(['path' => $backups->locateFolder($data['name'], $data['marker'])]);
    }

    /** The folder picker's "New folder" button. */
    public function createFolder(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'parent' => ['required', 'string', 'max:500'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $path = $backups->createFolder($data['parent'], $data['name']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => [$e->getMessage()]]);
        }

        return response()->json(['path' => $path], 201);
    }

    /**
     * Choose where backups are saved (null = the default folder), optionally
     * bringing the existing backups along. Takes effect immediately.
     */
    public function updateFolder(Request $request, DatabaseBackupService $backups, BackupScheduleService $schedule): JsonResponse
    {
        $data = $request->validate([
            'directory' => ['present', 'nullable', 'string', 'max:500'],
            'move_existing' => ['sometimes', 'boolean'],
        ]);

        $previous = $backups->directory();
        $directory = null;

        if (! empty($data['directory'])) {
            try {
                $directory = $backups->prepareFolder($data['directory']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['directory' => [$e->getMessage()]]);
            }

            // Picking the built-in folder is the same as having no custom folder.
            if ($backups->isDefaultDirectory($directory)) {
                $directory = null;
            }
        }

        $schedule->save(['directory' => $directory]);

        $moved = $request->boolean('move_existing') ? $backups->moveFiles($previous, $backups->directory()) : 0;

        $this->writeAudit($request, 'system.backup_folder_changed', [
            'folder' => $backups->directory(),
            'previous_folder' => $previous,
            'files_moved' => $moved,
        ], description: $directory === null ? 'Set backups to be saved in the default folder' : 'Changed the folder where backups are saved');

        $message = 'Backup folder saved.';
        if ($moved > 0) {
            $message .= " {$moved} backup ".($moved === 1 ? 'file was' : 'files were').' moved to the new folder.';
        }

        return response()->json([
            'message' => $message,
            'moved' => $moved,
            'schedule' => array_merge($schedule->get(), $schedule->summary()),
            'folder' => $this->folderPayload($backups),
        ]);
    }

    public function createBackup(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        $created = $backups->writeToDisk();

        $this->writeAudit($request, 'system.backup_created', [
            'name' => $created['name'],
            'size' => $created['size'],
            'format' => 'sql',
            'folder' => dirname($created['path']),
        ], description: "Created a backup ({$created['name']})");

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

        $this->writeAudit($request, 'system.backup_deleted', ['name' => $filename], description: "Deleted backup {$filename}");

        return response()->json(['message' => 'Backup deleted.']);
    }

    /** Delete several stored backups at once (each with its JSON companion). */
    public function bulkDestroyBackups(Request $request, DatabaseBackupService $backups): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string', 'distinct', 'regex:/^finann-backup-\d{8}-\d{6}\.sql$/'],
        ]);

        $deleted = 0;
        $skipped = [];

        foreach ($data['ids'] as $name) {
            try {
                $backups->deleteFile($name);
                $deleted++;
            } catch (InvalidArgumentException) {
                $skipped[] = ['id' => $name, 'label' => $name, 'reason' => 'Already removed.'];
            }
        }

        if ($deleted > 0) {
            $this->writeAudit($request, 'system.backup_deleted', [
                'count' => $deleted,
                'names' => array_slice($data['ids'], 0, 20),
            ], description: "Deleted {$deleted} ".($deleted === 1 ? 'backup' : 'backups'));
        }

        return response()->json(['deleted' => $deleted, 'skipped' => $skipped]);
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
        ], description: 'Cleared all reconciliation data');

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
     * Where backups are kept now, and whether that is the built-in folder.
     *
     * @return array{path: string, default_path: string, is_default: bool, problem: string|null}
     */
    private function folderPayload(DatabaseBackupService $backups): array
    {
        return [
            'path' => str_replace('/', DIRECTORY_SEPARATOR, $backups->directory()),
            'default_path' => str_replace('/', DIRECTORY_SEPARATOR, $backups->defaultDirectory()),
            'is_default' => $backups->customDirectory() === null,
            'problem' => $backups->folderProblem(),
        ];
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
        ?string $description = null,
    ): void {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'auditable_type' => $auditableType ?? 'system',
            'auditable_id' => $auditableId ?? 0,
            'description' => $description ?? $action,
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
