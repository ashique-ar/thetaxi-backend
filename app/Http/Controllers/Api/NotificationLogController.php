<?php
// app/Http/Controllers/Api/NotificationLogController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Http\Requests\NotificationLog\CreateNotificationLogRequest;
use App\Http\Requests\NotificationLog\UpdateNotificationLogRequest;
use App\Http\Resources\NotificationLog\NotificationLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:notification-logs.view')->only(['index','show']);
        $this->middleware('permission:notification-logs.create')->only(['store', 'sendBulk']);
        $this->middleware('permission:notification-logs.edit')->only(['update', 'retry']);
        $this->middleware('permission:notification-logs.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = NotificationLog::query();
        return NotificationLogResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateNotificationLogRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $log = NotificationLog::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Notification log created',
            'data'=>['log'=>new NotificationLogResource($log)]
        ],201);
    }

    public function show(NotificationLog $notificationLog): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['log'=>new NotificationLogResource($notificationLog)]
        ]);
    }

    public function update(UpdateNotificationLogRequest $request, NotificationLog $notificationLog): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $notificationLog->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Notification log updated',
            'data'=>['log'=>new NotificationLogResource($notificationLog)]
        ]);
    }

    public function destroy(NotificationLog $notificationLog): JsonResponse
    {
        $notificationLog->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Notification log deleted'
        ]);
    }

    public function retry(Request $request, NotificationLog $notificationLog): JsonResponse
    {
        $notificationLog->update([
            'status' => 'queued',
            'sent_at' => now(),
            'updated_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Notification retry queued',
            'data' => ['log' => new NotificationLogResource($notificationLog)]
        ]);
    }

    public function sendBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'exists:notification_templates,id'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['required', 'string'],
            'data' => ['sometimes', 'array'],
        ]);

        $template = NotificationTemplate::findOrFail($data['template_id']);
        $logs = collect($data['recipients'])->map(function () use ($request, $template) {
            return NotificationLog::create([
                'template_id' => $template->id,
                'content' => $template->body,
                'channel' => $template->channel,
                'sent_at' => now(),
                'status' => 'queued',
                'created_user_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Bulk notifications queued',
            'data' => ['logs' => NotificationLogResource::collection($logs)]
        ], 202);
    }
}
