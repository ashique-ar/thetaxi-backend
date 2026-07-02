<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemBackupController extends Controller
{
    private const DISK = 'backups';
    private const VERSION = 1;
    private const EXTENSION = '.json.gz';

    public function __construct()
    {
        $this->middleware('permission:system.manage');
    }

    public function index(): JsonResponse
    {
        $backups = collect(Storage::disk(self::DISK)->files())
            ->filter(fn (string $path) => str_ends_with($path, self::EXTENSION))
            ->map(fn (string $path) => $this->backupItem($path))
            ->filter()
            ->sortByDesc('created_at')
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $backups,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tables = $this->tableNames();
        $payload = [
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'connection' => DB::connection()->getDriverName(),
            'database' => DB::connection()->getDatabaseName(),
            'created_by' => $request->user()?->id,
            'scope' => 'database',
            'storage' => [
                'disk' => self::DISK,
                'driver' => config('filesystems.disks.' . self::DISK . '.driver'),
                'bucket' => config('filesystems.disks.' . self::DISK . '.bucket'),
                'prefix' => config('filesystems.disks.' . self::DISK . '.root'),
            ],
            'tables' => [],
        ];

        foreach ($tables as $table) {
            $payload['tables'][$table] = DB::table($table)->orderBy($this->firstColumn($table))->get()->map(
                fn ($row) => (array) $row
            )->values()->all();
        }

        $path = 'database-backup-' . now()->format('Ymd-His') . self::EXTENSION;
        Storage::disk(self::DISK)->put($path, gzencode(json_encode($payload, JSON_THROW_ON_ERROR)));

        return response()->json([
            'status' => 'success',
            'message' => 'Backup created successfully.',
            'data' => $this->backupItem($path),
        ], 201);
    }

    public function show(string $backup): JsonResponse
    {
        $item = $this->backupItem($this->normalizePath($backup));

        abort_if(!$item, 404, 'Backup not found.');

        return response()->json([
            'status' => 'success',
            'data' => $item,
        ]);
    }

    public function download(string $backup): StreamedResponse
    {
        $path = $this->normalizePath($backup);

        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'Backup not found.');

        return Storage::disk(self::DISK)->download($path, basename($path));
    }

    public function restore(string $backup): JsonResponse
    {
        $path = $this->normalizePath($backup);

        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'Backup not found.');

        $decoded = gzdecode(Storage::disk(self::DISK)->get($path));
        abort_if($decoded === false, 422, 'Backup file is not readable.');

        $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        abort_if(($payload['version'] ?? null) !== self::VERSION || !is_array($payload['tables'] ?? null), 422, 'Unsupported backup format.');

        DB::transaction(function () use ($payload) {
            $tables = array_keys($payload['tables']);
            $this->clearTables($tables);
            $this->disableConstraintsForInsert();

            try {
                foreach ($payload['tables'] as $table => $rows) {
                    foreach (array_chunk($rows, 500) as $chunk) {
                        if (!empty($chunk)) {
                            DB::table($table)->insert($chunk);
                        }
                    }
                }
            } finally {
                $this->enableConstraintsAfterInsert();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Backup restored successfully.',
        ]);
    }

    public function destroy(string $backup): JsonResponse
    {
        $path = $this->normalizePath($backup);

        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'Backup not found.');

        Storage::disk(self::DISK)->delete($path);

        return response()->json([
            'status' => 'success',
            'message' => 'Backup deleted successfully.',
        ]);
    }

    private function backupItem(string $path): ?array
    {
        if (!Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $timestamp = Storage::disk(self::DISK)->lastModified($path);

        return [
            'id' => $this->backupId($path),
            'filename' => basename($path),
            'size' => Storage::disk(self::DISK)->size($path),
            'created_at' => date('c', $timestamp),
            'type' => 'manual',
            'status' => 'completed',
            'storage' => [
                'disk' => self::DISK,
                'driver' => config('filesystems.disks.' . self::DISK . '.driver'),
                'bucket' => config('filesystems.disks.' . self::DISK . '.bucket'),
                'prefix' => config('filesystems.disks.' . self::DISK . '.root'),
            ],
            'scope' => 'database',
        ];
    }

    private function normalizePath(string $backup): string
    {
        $encoded = strtr($backup, '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $decoded = base64_decode($encoded, true);
        $candidate = $decoded ?: $backup;
        $candidate = ltrim(str_replace('\\', '/', $candidate), '/');

        return basename($candidate);
    }

    private function backupId(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    private function tableNames(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return collect(DB::select("select tablename as name from pg_tables where schemaname = 'public' order by tablename"))
                ->pluck('name')
                ->all();
        }

        if ($driver === 'sqlite') {
            return collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"))
                ->pluck('name')
                ->all();
        }

        return collect(DB::select('show full tables where Table_type = "BASE TABLE"'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->sort()
            ->values()
            ->all();
    }

    private function firstColumn(string $table): string
    {
        return DB::getSchemaBuilder()->getColumnListing($table)[0] ?? 'id';
    }

    private function clearTables(array $tables): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $quoted = collect($tables)->map(fn (string $table) => '"' . str_replace('"', '""', $table) . '"')->implode(', ');
            DB::statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        try {
            foreach (array_reverse($tables) as $table) {
                DB::table($table)->delete();
            }
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }
    }

    private function disableConstraintsForInsert(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('SET session_replication_role = replica'),
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = OFF'),
            default => null,
        };
    }

    private function enableConstraintsAfterInsert(): void
    {
        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement('SET session_replication_role = DEFAULT'),
            'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
            'sqlite' => DB::statement('PRAGMA foreign_keys = ON'),
            default => null,
        };
    }
}
