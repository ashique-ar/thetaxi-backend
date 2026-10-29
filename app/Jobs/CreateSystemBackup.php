<?php

namespace App\Jobs;

use App\Http\Controllers\Api\SystemBackupController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateSystemBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(public readonly string $taskId)
    {
        $this->onQueue('exports');
    }

    public function handle(SystemBackupController $backups): void
    {
        DB::table('system_background_tasks')->where('id', $this->taskId)->update(['status' => 'processing', 'updated_at' => now()]);
        try {
            $item = $backups->createBackupPayload(DB::table('system_background_tasks')->where('id', $this->taskId)->value('user_id'));
            DB::table('system_background_tasks')->where('id', $this->taskId)->update(['status' => 'completed', 'progress' => 100, 'result' => json_encode($item), 'updated_at' => now()]);
        } catch (Throwable $e) {
            Log::error('Background database backup failed.', ['task_id' => $this->taskId, 'exception' => $e]);
            DB::table('system_background_tasks')->where('id', $this->taskId)->update(['status' => 'failed', 'error' => 'Backup creation failed. Contact an administrator.', 'updated_at' => now()]);
            throw $e;
        }
    }
}
