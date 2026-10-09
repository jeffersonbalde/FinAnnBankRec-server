<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Exports application tables to SQL (+ JSON companion) backups.
 */
class DatabaseBackupService
{
    /**
     * Tables in dependency order (parents before children).
     *
     * @var list<string>
     */
    public const TABLES = [
        'users',
        'bank_accounts',
        'signatories',
        'reference_uacs',
        'reconciliations',
        'import_batches',
        'check_issuances',
        'bank_transactions',
        'match_runs',
        'reconciling_items',
        'notifications',
        'audit_logs',
    ];

    /** The built-in folder, used until the administrator picks another one. */
    public function defaultDirectory(): string
    {
        return (string) config('backup.path', storage_path('app/backups'));
    }

    /** The folder the administrator chose in Backup & Security, if any. */
    public function customDirectory(): ?string
    {
        try {
            return app(BackupScheduleService::class)->get()['directory'];
        } catch (\Throwable) {
            return null; // settings are optional during early boot/tests
        }
    }

    /**
     * Where backups are written and listed: the chosen folder, or the default
     * one when none is chosen or the chosen one cannot be used (e.g. a drive
     * that was unplugged), so a scheduled backup is never skipped.
     */
    public function directory(): string
    {
        $custom = $this->customDirectory();

        if ($custom !== null && $this->isUsableDirectory($custom)) {
            return $custom;
        }

        $dir = $this->defaultDirectory();

        // Last resort (e.g. no permission to create the default folder): keep backups inside the app.
        if (! $this->isUsableDirectory($dir)) {
            $dir = storage_path('app/backups');

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        return $dir;
    }

    public function isDefaultDirectory(string $path): bool
    {
        return $this->samePath($path, $this->defaultDirectory());
    }

    /** Why the chosen folder cannot be used right now, or null when it is fine (or not set). */
    public function folderProblem(): ?string
    {
        $custom = $this->customDirectory();

        if ($custom === null || $this->isUsableDirectory($custom)) {
            return null;
        }

        return 'The chosen folder cannot be reached or written to, so backups are being saved in the default folder for now.';
    }

    /**
     * Check a folder typed by the administrator and make it ready: it must be a
     * full path, outside the web-served folder, and writable (it is created
     * when missing). Returns the cleaned path.
     *
     * @throws InvalidArgumentException with a message fit for the user
     */
    public function prepareFolder(string $input): string
    {
        $path = trim($input);

        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Enter the full path of a folder.');
        }

        if (! $this->isAbsolutePath($path)) {
            throw new InvalidArgumentException(PHP_OS_FAMILY === 'Windows'
                ? 'Use the full path of the folder, for example D:\\FABReS Backups.'
                : 'Use the full path of the folder, for example /var/backups/fabres.');
        }

        if (preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path) === 1) {
            throw new InvalidArgumentException('The folder path cannot contain "..".');
        }

        $path = $this->trimTrailingSeparators($path);

        if ($this->isInside($path, public_path())) {
            throw new InvalidArgumentException("Choose a folder outside the website's public folder, so backups cannot be downloaded by anyone.");
        }

        if (file_exists($path) && ! is_dir($path)) {
            throw new InvalidArgumentException('That path is a file, not a folder.');
        }

        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new InvalidArgumentException('The folder does not exist and could not be created. Check the path and that the server is allowed to write there.');
        }

        if (! $this->canWriteTo($path)) {
            throw new InvalidArgumentException('The server cannot write to that folder. Choose another folder or allow write access.');
        }

        return $path;
    }

    /**
     * The folders inside `$path` (or the drives, when no path is given on
     * Windows), for the folder picker. Only folders are listed, never files.
     *
     * @return array{path: string|null, parent: string|null, folders: list<array{name: string, path: string}>, writable: bool}
     *
     * @throws InvalidArgumentException when the folder cannot be opened
     */
    public function browse(?string $path = null): array
    {
        $path = trim((string) $path);
        $isWindows = PHP_OS_FAMILY === 'Windows';

        if ($path === '' && $isWindows) {
            $drives = [];

            foreach (range('A', 'Z') as $letter) {
                if (@is_dir($letter.':\\')) {
                    $drives[] = ['name' => $letter.':\\', 'path' => $letter.':\\'];
                }
            }

            return ['path' => null, 'parent' => null, 'folders' => $drives, 'writable' => false];
        }

        $path = $path === '' ? '/' : $path;

        if (! $this->isAbsolutePath($path) || str_contains($path, "\0") || ! @is_dir($path)) {
            throw new InvalidArgumentException('That folder cannot be opened.');
        }

        $path = $this->trimTrailingSeparators((string) (realpath($path) ?: $path));
        $names = @scandir($path);

        if ($names === false) {
            throw new InvalidArgumentException('That folder cannot be opened. The server may not be allowed to read it.');
        }

        $folders = [];

        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.' || $name[0] === '$') {
                continue;
            }

            $full = rtrim($path, '/\\').DIRECTORY_SEPARATOR.$name;

            if (@is_dir($full)) {
                $folders[] = ['name' => $name, 'path' => $full];
            }
        }

        usort($folders, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        $parent = dirname($path);
        $atRoot = $this->samePath($parent, $path);

        return [
            'path' => $path,
            // "" means "back to the list of drives" on Windows.
            'parent' => $atRoot ? ($isWindows ? '' : null) : $parent,
            'folders' => array_slice($folders, 0, 500),
            'writable' => @is_writable($path) || $this->canWriteTo($path),
        ];
    }

    /**
     * Find the full path of a folder the user picked in their own browser.
     * A browser only reveals the folder's name, so it first drops a marker
     * file inside it; here we look for a folder of that name that holds the
     * marker. Only works when this server runs on the same computer; returns
     * null otherwise. The search is bounded in depth and time.
     */
    public function locateFolder(string $name, string $marker, int $seconds = 12): ?string
    {
        if ($name === '' || preg_match('/[\\\\\/]/', $name) === 1 || preg_match('/^\.fabres-check-[a-f0-9]{8,64}$/', $marker) !== 1) {
            return null;
        }

        $deadline = microtime(true) + $seconds;
        $skip = ['appdata', 'windows', 'program files', 'program files (x86)', 'programdata', 'node_modules', 'vendor',
            'system volume information', 'recovery', 'proc', 'sys', 'dev', 'snap', 'lost+found'];

        $queue = new \SplQueue;
        foreach ($this->searchRoots() as $root) {
            $queue->enqueue([$root, 0]);
        }

        $visited = 0;

        while (! $queue->isEmpty() && microtime(true) < $deadline && $visited < 300000) {
            [$dir, $depth] = $queue->dequeue();
            $entries = @scandir($dir);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.' || $entry[0] === '$') {
                    continue;
                }

                $full = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$entry;

                if (! @is_dir($full) || in_array(strtolower($entry), $skip, true)) {
                    continue;
                }

                if (strcasecmp($entry, $name) === 0 && @is_file($full.DIRECTORY_SEPARATOR.$marker)) {
                    return $full;
                }

                if ($depth < 9) {
                    $queue->enqueue([$full, $depth + 1]);
                }
            }

            $visited++;
        }

        return null;
    }

    /**
     * Where to start looking for a picked folder: people's own folders first
     * (that is where nearly everyone saves), then the other drives.
     *
     * @return list<string>
     */
    private function searchRoots(): array
    {
        $roots = [];

        if (PHP_OS_FAMILY === 'Windows') {
            foreach ((array) glob((getenv('SystemDrive') ?: 'C:').'\\Users\\*', GLOB_ONLYDIR) as $profile) {
                if (! in_array(strtolower(basename($profile)), ['public', 'default', 'default user', 'all users'], true)) {
                    $roots[] = $profile;
                }
            }
            foreach (range('A', 'Z') as $letter) {
                if (@is_dir($letter.':\\')) {
                    $roots[] = $letter.':\\';
                }
            }

            return $roots;
        }

        foreach ((array) glob('/home/*', GLOB_ONLYDIR) as $home) {
            $roots[] = $home;
        }

        return array_merge($roots, ['/mnt', '/media', '/Volumes', '/Users', '/srv', '/opt']);
    }

    /**
     * Make a new folder inside `$parent` for the folder picker.
     *
     * @throws InvalidArgumentException with a message fit for the user
     */
    public function createFolder(string $parent, string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[<>:"\/\\\\|?*\x00-\x1f]/', $name) === 1 || str_ends_with($name, '.')) {
            throw new InvalidArgumentException('A folder name cannot be empty, end with a dot, or contain \ / : * ? " < > |');
        }

        if (! $this->isAbsolutePath($parent) || ! @is_dir($parent)) {
            throw new InvalidArgumentException('The folder to create it in cannot be opened.');
        }

        $path = rtrim($parent, '/\\').DIRECTORY_SEPARATOR.$name;

        if (file_exists($path)) {
            throw new InvalidArgumentException('A folder or file with that name already exists here.');
        }

        if (! @mkdir($path, 0755)) {
            throw new InvalidArgumentException('The folder could not be created. The server may not be allowed to write here.');
        }

        return $path;
    }

    /**
     * Move the backup files from one folder to another (used when the
     * administrator changes the folder and wants the old backups to follow).
     *
     * @return int number of files moved
     */
    public function moveFiles(string $from, string $to): int
    {
        if (! is_dir($from) || $this->samePath($from, $to)) {
            return 0;
        }

        $moved = 0;

        foreach ($this->listFiles($from) as $file) {
            $source = rtrim($from, '/\\').DIRECTORY_SEPARATOR.$file['name'];
            $target = rtrim($to, '/\\').DIRECTORY_SEPARATOR.$file['name'];

            if (is_file($target)) {
                continue;
            }

            if (@copy($source, $target)) {
                @touch($target, (int) filemtime($source));
                @unlink($source);
                $moved++;
            }
        }

        return $moved;
    }

    private function isAbsolutePath(string $path): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? preg_match('#^([A-Za-z]:[\\\\/]|\\\\\\\\[^\\\\/]+[\\\\/][^\\\\/]+)#', $path) === 1
            : str_starts_with($path, '/');
    }

    private function isUsableDirectory(string $dir): bool
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        // is_writable() is wrong for some Windows folders (Music, Pictures...): try a real write.
        return is_writable($dir) || $this->canWriteTo($dir);
    }

    private function canWriteTo(string $dir): bool
    {
        $probe = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'.write-test-'.uniqid();

        if (@file_put_contents($probe, 'ok') === false) {
            return false;
        }

        @unlink($probe);

        return true;
    }

    private function trimTrailingSeparators(string $path): string
    {
        $trimmed = rtrim($path, '/\\');

        // Keep the root of a drive ("D:\") or of the filesystem ("/") intact.
        if ($trimmed === '' || preg_match('/^[A-Za-z]:$/', $trimmed) === 1) {
            return $trimmed.DIRECTORY_SEPARATOR;
        }

        return $trimmed;
    }

    private function isInside(string $path, string $parent): bool
    {
        return str_starts_with($this->comparable($path).'/', $this->comparable($parent).'/');
    }

    private function samePath(string $a, string $b): bool
    {
        return $this->comparable($a) === $this->comparable($b);
    }

    private function comparable(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', (string) (realpath($path) ?: $path)), '/'));
    }

    /**
     * @return array{generated_at: string, app: string, tables: array<string, array<int, array<string, mixed>>>}
     */
    public function snapshot(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $tables[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'app' => (string) config('app.name'),
            'tables' => $tables,
        ];
    }

    public function filename(string $extension = 'sql'): string
    {
        $ext = ltrim(strtolower($extension), '.');

        return 'finann-backup-'.now()->format('Ymd-His').'.'.$ext;
    }

    /**
     * @return array{name: string, path: string, size: int, generated_at: string, format: string, json_name: string}
     */
    public function writeToDisk(?string $directory = null): array
    {
        $directory = $directory ?: $this->directory();
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $snapshot = $this->snapshot();
        $stamp = now()->format('Ymd-His');
        $sqlName = "finann-backup-{$stamp}.sql";
        $jsonName = "finann-backup-{$stamp}.json";
        $sqlPath = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$sqlName;
        $jsonPath = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$jsonName;

        file_put_contents($sqlPath, $this->toSql($snapshot));
        file_put_contents($jsonPath, json_encode($snapshot, JSON_PRETTY_PRINT));

        try {
            $retention = app(BackupScheduleService::class)->get()['retention_days'] ?? null;
            if ($retention !== null) {
                config(['backup.retention_days' => (int) $retention]);
            }
        } catch (\Throwable) {
            // schedule optional during early boot/tests
        }

        $this->pruneOldBackups($directory);

        return [
            'name' => $sqlName,
            'path' => $sqlPath,
            'size' => (int) filesize($sqlPath),
            'generated_at' => $snapshot['generated_at'],
            'format' => 'sql',
            'json_name' => $jsonName,
        ];
    }

    /**
     * @param  array{generated_at?: string, app?: string, tables: array<string, array<int, array<string, mixed>>>}  $snapshot
     */
    public function toSql(array $snapshot): string
    {
        $generatedAt = $snapshot['generated_at'] ?? now()->toIso8601String();
        $app = $snapshot['app'] ?? (string) config('app.name');
        $driver = DB::connection()->getDriverName();
        $lines = [
            '-- ============================================================',
            '-- '.$app.' — bank reconciliation data backup',
            '-- Generated: '.$generatedAt,
            '-- Application: '.$app,
            '-- Source driver: '.$driver,
            '-- ============================================================',
            '',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        foreach (self::TABLES as $table) {
            $rows = $snapshot['tables'][$table] ?? [];
            $lines[] = '-- ------------------------------------------------------------';
            $lines[] = '-- Table: '.$table.' ('.count($rows).' rows)';
            $lines[] = '-- ------------------------------------------------------------';
            $lines[] = 'DELETE FROM `'.$table.'`;';

            if ($rows === []) {
                $lines[] = '';

                continue;
            }

            $columns = array_keys($rows[0]);
            $colList = implode(', ', array_map(fn ($c) => '`'.$c.'`', $columns));

            foreach (array_chunk($rows, 50) as $chunk) {
                $valueGroups = [];
                foreach ($chunk as $row) {
                    $values = [];
                    foreach ($columns as $col) {
                        $values[] = $this->sqlLiteral($row[$col] ?? null);
                    }
                    $valueGroups[] = '('.implode(', ', $values).')';
                }
                $lines[] = 'INSERT INTO `'.$table.'` ('.$colList.') VALUES';
                $lines[] = implode(",\n", $valueGroups).';';
            }
            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, format: string, size: int, generated_at: string|null}>
     */
    public function listFiles(?string $directory = null): array
    {
        $directory = $directory ?: $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (File::files($directory) as $file) {
            $name = $file->getFilename();
            if (! $this->isSafeBackupName($name)) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'format' => strtolower($file->getExtension()),
                'size' => $file->getSize(),
                'generated_at' => $this->createdAt($name) ?? date('c', $file->getMTime()),
            ];
        }

        usort($files, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $files;
    }

    /**
     * When a backup was made, read from its name (finann-backup-20261009-123817),
     * so moving or copying the file to another folder never changes it.
     */
    private function createdAt(string $filename): ?string
    {
        if (preg_match('/^finann-backup-(\d{8}-\d{6})\./', $filename, $m) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Ymd-His', $m[1], (string) config('app.timezone', 'UTC'))->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolvePath(string $filename, ?string $directory = null): string
    {
        if (! $this->isSafeBackupName($filename)) {
            throw new InvalidArgumentException('Invalid backup filename.');
        }

        $directory = $directory ?: $this->directory();
        $path = realpath($directory.DIRECTORY_SEPARATOR.$filename);
        $base = realpath($directory);

        if ($path === false || $base === false || ! str_starts_with($path, $base) || ! is_file($path)) {
            throw new InvalidArgumentException('Backup file not found.');
        }

        return $path;
    }

    public function deleteFile(string $filename, ?string $directory = null): void
    {
        $path = $this->resolvePath($filename, $directory);
        File::delete($path);

        $companion = preg_replace('/\.(sql|json)$/i', '', $filename);
        if (is_string($companion)) {
            foreach (['.sql', '.json'] as $ext) {
                $other = $companion.$ext;
                if ($other === $filename) {
                    continue;
                }
                try {
                    File::delete($this->resolvePath($other, $directory));
                } catch (InvalidArgumentException) {
                    // companion missing
                }
            }
        }
    }

    public function isSafeBackupName(string $filename): bool
    {
        return (bool) preg_match('/^finann-backup-\d{8}-\d{6}\.(sql|json)$/', $filename);
    }

    public function pruneOldBackups(?string $directory = null): int
    {
        $days = (int) config('backup.retention_days', 30);
        if ($days <= 0) {
            return 0;
        }

        $directory = $directory ?: $this->directory();
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach ($this->listFiles($directory) as $file) {
            $mtime = strtotime((string) $file['generated_at']) ?: 0;
            if ($mtime > 0 && $mtime < $cutoff) {
                try {
                    File::delete($this->resolvePath($file['name'], $directory));
                    $removed++;
                } catch (InvalidArgumentException) {
                    // ignore
                }
            }
        }

        return $removed;
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        return "'".str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $string
        )."'";
    }
}
