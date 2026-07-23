<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SystemBackupController extends Controller
{
    private const DISK = 'backups';
    private const VERSION = 2;
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

        $payload['manifest'] = $this->buildManifest($payload['tables']);

        $path = 'database-backup-' . now()->format('Ymd-His') . self::EXTENSION;
        $written = Storage::disk(self::DISK)->put(
            $path,
            gzencode(json_encode($payload, JSON_THROW_ON_ERROR)),
        );
        abort_unless($written && Storage::disk(self::DISK)->exists($path), 500, 'Backup could not be stored.');

        $verification = $this->verifyPayload($this->readPayload($path));
        if (! $verification['valid']) {
            Storage::disk(self::DISK)->delete($path);
            abort(500, 'Backup failed its post-write integrity verification.');
        }

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

    public function verify(string $backup): JsonResponse
    {
        $path = $this->normalizePath($backup);

        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'Backup not found.');

        return response()->json([
            'status' => 'success',
            'data' => $this->verifyPayload($this->readPayload($path)),
        ]);
    }

    public function restore(string $backup): JsonResponse
    {
        $path = $this->normalizePath($backup);

        abort_unless(Storage::disk(self::DISK)->exists($path), 404, 'Backup not found.');

        $payload = $this->readPayload($path);
        $verification = $this->verifyPayload($payload);
        abort_if(! $verification['valid'], 422, 'Backup integrity or schema verification failed.');

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

    private function readPayload(string $path): array
    {
        $decoded = gzdecode(Storage::disk(self::DISK)->get($path));
        abort_if($decoded === false, 422, 'Backup file is not readable.');

        try {
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(422, 'Backup JSON is not readable.');
        }

        abort_if(! is_array($payload), 422, 'Unsupported backup format.');

        return $payload;
    }

    private function buildManifest(array $tables): array
    {
        $manifest = [];

        foreach ($tables as $table => $rows) {
            $manifest[$table] = [
                'rows' => count($rows),
                'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
                'columns' => DB::getSchemaBuilder()->getColumnListing($table),
            ];
        }

        return [
            'algorithm' => 'sha256',
            'table_count' => count($tables),
            'row_count' => array_sum(array_column($manifest, 'rows')),
            'tables' => $manifest,
        ];
    }

    private function verifyPayload(array $payload): array
    {
        $issues = [];
        $tables = $payload['tables'] ?? null;
        $manifest = $payload['manifest'] ?? null;

        if (($payload['version'] ?? null) !== self::VERSION || ! is_array($tables) || ! is_array($manifest)) {
            $issues[] = 'unsupported_or_legacy_format';
        } else {
            $actual = $this->buildManifest($tables);

            if (! hash_equals(
                hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
                hash('sha256', json_encode($actual, JSON_THROW_ON_ERROR)),
            )) {
                $issues[] = 'manifest_checksum_mismatch';
            }

            $backupTables = array_keys($tables);
            $currentTables = $this->tableNames();
            sort($backupTables);
            sort($currentTables);

            if ($backupTables !== $currentTables) {
                $issues[] = 'database_schema_table_mismatch';
            }
        }

        return [
            'valid' => $issues === [],
            'version' => $payload['version'] ?? null,
            'scope' => $payload['scope'] ?? null,
            'created_at' => $payload['created_at'] ?? null,
            'table_count' => is_array($tables) ? count($tables) : 0,
            'row_count' => is_array($tables)
                ? collect($tables)->sum(fn ($rows) => is_array($rows) ? count($rows) : 0)
                : 0,
            'issues' => $issues,
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
