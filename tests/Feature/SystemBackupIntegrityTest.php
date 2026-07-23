<?php

use App\Http\Controllers\Api\SystemBackupController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config([
        'database.default' => 'backup_contract',
        'database.connections.backup_contract' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'filesystems.disks.backups' => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/backups'),
            'throw' => true,
        ],
    ]);

    DB::purge('backup_contract');
    Storage::fake('backups');

    Schema::create('bookings', function (Blueprint $table): void {
        $table->id();
        $table->string('status');
    });
    Schema::create('booking_payment_receipts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('booking_id');
        $table->decimal('amount', 12, 2);
    });

    DB::table('bookings')->insert(['id' => 10, 'status' => 'confirmed']);
    DB::table('booking_payment_receipts')->insert([
        'id' => 20,
        'booking_id' => 10,
        'amount' => 1250.50,
    ]);
});

afterEach(function (): void {
    DB::purge('backup_contract');
});

it('verifies and restores an isolated snapshot while refusing tampered data', function (): void {
    $controller = app(SystemBackupController::class);
    $request = Request::create('/api/system/backups', 'POST');
    $request->setUserResolver(fn () => (object) ['id' => 99]);

    $created = $controller->store($request)->getData(true)['data'];
    $verification = $controller->verify($created['id'])->getData(true)['data'];

    expect($verification)->toMatchArray([
        'valid' => true,
        'version' => 2,
        'scope' => 'database',
        'table_count' => 2,
        'row_count' => 2,
        'issues' => [],
    ]);

    DB::table('bookings')->where('id', 10)->update(['status' => 'cancelled']);
    DB::table('booking_payment_receipts')->delete();

    $controller->restore($created['id']);

    expect(DB::table('bookings')->where('id', 10)->value('status'))->toBe('confirmed')
        ->and((float) DB::table('booking_payment_receipts')->where('id', 20)->value('amount'))->toBe(1250.50);

    Schema::create('post_backup_schema_change', fn (Blueprint $table) => $table->id());
    $schemaMismatch = $controller->verify($created['id'])->getData(true)['data'];

    expect($schemaMismatch['valid'])->toBeFalse()
        ->and($schemaMismatch['issues'])->toContain('database_schema_table_mismatch')
        ->and(fn () => $controller->restore($created['id']))
        ->toThrow(HttpException::class, 'Backup integrity or schema verification failed.');

    Schema::drop('post_backup_schema_change');

    $payload = json_decode(
        gzdecode(Storage::disk('backups')->get($created['filename'])),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $payload['tables']['bookings'][0]['status'] = 'tampered';
    Storage::disk('backups')->put(
        $created['filename'],
        gzencode(json_encode($payload, JSON_THROW_ON_ERROR)),
    );

    $failedVerification = $controller->verify($created['id'])->getData(true)['data'];

    expect($failedVerification['valid'])->toBeFalse()
        ->and($failedVerification['issues'])->toContain('manifest_checksum_mismatch')
        ->and(fn () => $controller->restore($created['id']))
        ->toThrow(HttpException::class, 'Backup integrity or schema verification failed.');

    expect(DB::table('bookings')->where('id', 10)->value('status'))->toBe('confirmed');
});
