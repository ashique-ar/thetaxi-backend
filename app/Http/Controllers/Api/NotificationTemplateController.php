<?php
// app/Http/Controllers/Api/NotificationTemplateController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use App\Http\Requests\NotificationTemplate\CreateNotificationTemplateRequest;
use App\Http\Requests\NotificationTemplate\UpdateNotificationTemplateRequest;
use App\Http\Resources\NotificationTemplate\NotificationTemplateResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationTemplateController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:notification-templates.view')->only(['index', 'show']);
        $this->middleware('permission:notification-templates.create')->only(['store', 'sendTest']);
        $this->middleware('permission:notification-templates.edit')->only(['update']);
        $this->middleware('permission:notification-templates.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = NotificationTemplate::query();
        return NotificationTemplateResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateNotificationTemplateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $tmpl = NotificationTemplate::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Template created',
            'data' => ['template' => new NotificationTemplateResource($tmpl)]
        ], 201);
    }

    public function show(NotificationTemplate $notification_template): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['template' => new NotificationTemplateResource($notification_template)]
        ]);
    }

    public function update(UpdateNotificationTemplateRequest $request, NotificationTemplate $notification_template): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $notification_template->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Template updated',
            'data' => ['template' => new NotificationTemplateResource($notification_template)]
        ]);
    }

    public function destroy(NotificationTemplate $notification_template): JsonResponse
    {
        $notification_template->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Template deleted'
        ]);
    }

    public function preview(Request $request, NotificationTemplate $notification_template): JsonResponse
    {
        $data = $request->input('data', []);

        return response()->json([
            'status' => 'success',
            'data' => [
                'subject' => $this->renderTemplate($notification_template->subject ?? '', $data),
                'body' => $this->renderTemplate($notification_template->body, $data),
            ],
        ]);
    }

    public function sendTest(Request $request, NotificationTemplate $notification_template): JsonResponse
    {
        $data = $request->validate([
            'recipient' => ['required', 'string', 'max:255'],
            'data' => ['sometimes', 'array'],
        ]);

        $body = $this->renderTemplate($notification_template->body, $data['data'] ?? []);
        $log = $notification_template->logs()->create([
            'content' => $body,
            'channel' => $notification_template->channel,
            'sent_at' => now(),
            'status' => 'queued',
            'created_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Test notification queued',
            'data' => ['log' => $log, 'recipient' => $data['recipient']],
        ], 202);
    }

    private function renderTemplate(?string $template, array $data): string
    {
        $rendered = $template ?? '';

        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $rendered = str_replace(
                    ['{{' . $key . '}}', '{{ ' . $key . ' }}'],
                    (string) $value,
                    $rendered
                );
            }
        }

        return $rendered;
    }
}
