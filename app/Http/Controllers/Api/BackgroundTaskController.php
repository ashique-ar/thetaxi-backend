<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BackgroundTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tasks = DB::table('system_background_tasks')
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->latest()->limit(30)->get();

        foreach ($tasks as $task) {
            if ($task->type !== 'sms_campaign_launch') continue;
            $campaign = DB::table('sms_campaigns')->where('id', $task->related_id)->first();
            if (!$campaign) {
                $task->status = 'failed';
                $task->error = 'Campaign is no longer available.';
                continue;
            }
            $task->label = 'SMS campaign: ' . $campaign->name;
            $total = max(0, (int) $campaign->total_recipients);
            $completed = (int) $campaign->sent_recipients + (int) $campaign->delivered_recipients + (int) $campaign->failed_recipients;
            $task->progress = $total ? min(100, (int) round($completed * 100 / $total)) : 0;
            if ($campaign->status === 'failed') {
                $task->status = 'failed';
                $task->error = 'Campaign failed. Open the campaign for details.';
            } elseif ($campaign->status === 'completed') {
                $task->status = 'completed';
                $task->progress = 100;
            } elseif ($campaign->status === 'processing') {
                $task->status = 'processing';
            }
        }

        return response()->json(['status' => 'success', 'data' => $tasks]);
    }

    public function dismiss(Request $request, string $task): JsonResponse
    {
        $record = DB::table('system_background_tasks')
            ->where('id', $task)->where('user_id', $request->user()->getAuthIdentifier())->first();
        abort_unless($record, 404);
        abort_unless(in_array($record->status, ['completed', 'failed'], true), 409, 'Active tasks cannot be dismissed.');
        DB::table('system_background_tasks')->where('id', $task)->delete();

        return response()->json(['status' => 'success']);
    }
}
