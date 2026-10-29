<?php

use App\Http\Controllers\Api\BackgroundTaskController;
use App\Http\Controllers\Api\SystemBackupController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Schema::create('system_background_tasks', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('type');
        $table->string('label');
        $table->string('related_id')->nullable();
        $table->string('status');
        $table->unsignedTinyInteger('progress')->default(0);
        $table->text('error')->nullable();
        $table->json('result')->nullable();
        $table->timestamps();
    });
    Schema::create('sms_campaigns', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->unsignedInteger('total_recipients')->default(0);
        $table->unsignedInteger('sent_recipients')->default(0);
        $table->unsignedInteger('delivered_recipients')->default(0);
        $table->unsignedInteger('failed_recipients')->default(0);
        $table->string('status');
    });
});

it('persists SMS completion before allowing a background task to be dismissed', function (): void {
    $userId = '11111111-1111-4111-8111-111111111111';
    $taskId = '22222222-2222-4222-8222-222222222222';
    $campaignId = '33333333-3333-4333-8333-333333333333';
    DB::table('system_background_tasks')->insert([
        'id' => $taskId, 'user_id' => $userId, 'type' => 'sms_campaign_launch',
        'label' => 'Launch SMS campaign', 'related_id' => $campaignId,
        'status' => 'processing', 'progress' => 50, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sms_campaigns')->insert([
        'id' => $campaignId, 'name' => 'Test campaign', 'total_recipients' => 2,
        'sent_recipients' => 2, 'delivered_recipients' => 0, 'failed_recipients' => 0,
        'status' => 'completed',
    ]);
    $request = Request::create('/api/system/background-tasks', 'GET');
    $request->setUserResolver(fn () => new class($userId) {
        public function __construct(private string $id) {}
        public function getAuthIdentifier(): string { return $this->id; }
    });

    $response = app(BackgroundTaskController::class)->index($request)->getData(true);

    expect($response['data'][0]['status'])->toBe('completed')
        ->and($response['data'][0]['progress'])->toBe(100)
        ->and(DB::table('system_background_tasks')->where('id', $taskId)->value('status'))->toBe('completed');

    expect(app(BackgroundTaskController::class)->dismiss($request, $taskId)->getStatusCode())->toBe(200)
        ->and(DB::table('system_background_tasks')->where('id', $taskId)->exists())->toBeFalse();
});

it('runs a queued backup and records the result on its background task', function (): void {
    Storage::fake('backups');
    $userId = '11111111-1111-4111-8111-111111111111';
    $request = Request::create('/api/system/backups/queue', 'POST');
    $request->setUserResolver(fn () => new class($userId) {
        public function __construct(public string $id) {}
        public function getAuthIdentifier(): string { return $this->id; }
    });

    $response = app(SystemBackupController::class)->queue($request);
    $taskId = $response->getData(true)['data']['id'];
    $task = DB::table('system_background_tasks')->where('id', $taskId)->first();

    expect($response->getStatusCode())->toBe(202)
        ->and($task->status)->toBe('completed')
        ->and((int) $task->progress)->toBe(100)
        ->and($task->result)->not->toBeNull()
        ->and(Storage::disk('backups')->files())->toHaveCount(1);
});
