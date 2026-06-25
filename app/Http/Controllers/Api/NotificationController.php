<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\NotificationLog;
use App\Services\MailDispatchService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    protected MailDispatchService $mailDispatchService;

    // Enforce authentication and permissions for notification endpoints
    public function __construct(MailDispatchService $mailDispatchService)
    {
        $this->mailDispatchService = $mailDispatchService;
        $this->middleware('auth:api');
        $this->middleware('permission:notifications.send')->only(['sendNotification']);
        $this->middleware('permission:notifications.broadcast')->only(['broadcastNotification']);
        $this->middleware('permission:notifications.schedule')->only(['scheduleNotification']);
        $this->middleware('permission:notifications.email')->only(['sendEmailNotification']);
        $this->middleware('permission:notifications.templates')->only(['getEmailTemplates']);
        $this->middleware('permission:notifications.create-template')->only(['createEmailTemplate']);
    }

    /**
     * Get user notifications
     * GET /api/notifications
     */
    public function getUserNotifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->get('limit', 20);
        $unreadOnly = $request->get('unread_only', false);

        $query = $user->notifications();

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $notifications = $query->latest()
            ->paginate($limit);

        return response()->json([
            'status' => 'success',
            'data' => [
                'notifications' => $notifications->items(),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'last_page' => $notifications->lastPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total()
                ]
            ]
        ]);
    }

    /**
     * Mark notification as read
     * POST /api/notifications/mark-read/{id}
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->find($id);

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marked as read'
        ]);
    }

    /**
     * Mark all notifications as read
     * POST /api/notifications/mark-all-read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'All notifications marked as read'
        ]);
    }

    /**
     * Delete notification
     * DELETE /api/notifications/{id}
     */
    public function deleteNotification(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->find($id);

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification deleted successfully'
        ]);
    }

    /**
     * Get unread notifications count
     * GET /api/notifications/unread-count
     */
    public function getUnreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $user->unreadNotifications->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'unread_count' => $count
            ]
        ]);
    }

    /**
     * Send notification
     * POST /api/notifications/send
     */
    public function sendNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'type' => 'required|string|in:info,success,warning,error',
            'data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::findOrFail($request->user_id);
            
            $notificationData = [
                'title' => $request->title,
                'message' => $request->message,
                'type' => $request->type,
                'data' => $request->data ?? []
            ];

            $user->notify(new \App\Notifications\GeneralNotification($notificationData));

            return response()->json([
                'status' => 'success',
                'message' => 'Notification sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Broadcast notification
     * POST /api/notifications/broadcast
     */
    public function broadcastNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'type' => 'required|string|in:info,success,warning,error',
            'user_types' => 'nullable|array',
            'user_types.*' => 'string|in:customer,driver,agent,admin',
            'data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = User::query();
            
            if ($request->has('user_types') && !empty($request->user_types)) {
                $query->whereHas('roles', function ($roleQuery) use ($request) {
                    $roleQuery->whereIn('name', $request->user_types);
                });
            }

            $users = $query->get();
            
            $notificationData = [
                'title' => $request->title,
                'message' => $request->message,
                'type' => $request->type,
                'data' => $request->data ?? []
            ];

            Notification::send($users, new \App\Notifications\GeneralNotification($notificationData));

            return response()->json([
                'status' => 'success',
                'message' => "Notification sent to {$users->count()} users"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to broadcast notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Schedule notification
     * POST /api/notifications/schedule
     */
    public function scheduleNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'type' => 'required|string|in:info,success,warning,error',
            'scheduled_at' => 'required|date|after:now',
            'data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = User::findOrFail($request->user_id);
            
            $notificationData = [
                'title' => $request->title,
                'message' => $request->message,
                'type' => $request->type,
                'data' => $request->data ?? [],
                'scheduled_at' => $request->scheduled_at
            ];

            // You can implement a job queue for this
            \App\Jobs\SendScheduledNotification::dispatch($user, $notificationData)
                ->delay(now()->parse($request->scheduled_at));

            return response()->json([
                'status' => 'success',
                'message' => 'Notification scheduled successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to schedule notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send email notification
     * POST /api/notifications/email
     */
    public function sendEmailNotification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'template' => 'nullable|string',
            'data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $emailData = [
                'subject' => $request->subject,
                'message' => $request->message,
                'template' => $request->template ?? 'general',
                'data' => $request->data ?? []
            ];

            $this->mailDispatchService->sendToCustomer(
                $request->email,
                new \App\Mail\GeneralMail($emailData)
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Email notification sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send email notification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get email templates
     * GET /api/notifications/email-templates
     */
    public function getEmailTemplates(): JsonResponse
    {
        $templates = [
            [
                'id' => 1,
                'name' => 'General',
                'slug' => 'general',
                'description' => 'General purpose email template'
            ],
            [
                'id' => 2,
                'name' => 'Booking Confirmation',
                'slug' => 'booking-confirmation',
                'description' => 'Template for booking confirmation emails'
            ],
            [
                'id' => 3,
                'name' => 'Booking Reminder',
                'slug' => 'booking-reminder',
                'description' => 'Template for booking reminder emails'
            ],
            [
                'id' => 4,
                'name' => 'Payment Receipt',
                'slug' => 'payment-receipt',
                'description' => 'Template for payment receipt emails'
            ],
            [
                'id' => 5,
                'name' => 'Welcome',
                'slug' => 'welcome',
                'description' => 'Template for welcome emails'
            ]
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'templates' => $templates
            ]
        ]);
    }

    /**
     * Create email template
     * POST /api/notifications/email-templates
     */
    public function createEmailTemplate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:notification_templates,slug',
            'description' => 'nullable|string|max:500',
            'subject' => 'required|string|max:255',
            'content' => 'required|string',
            'variables' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $template = \App\Models\NotificationTemplate::create([
                'name' => $request->name,
                'slug' => $request->slug,
                'description' => $request->description,
                'subject' => $request->subject,
                'content' => $request->content,
                'variables' => $request->variables ?? [],
                'type' => 'email',
                'is_active' => true
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Email template created successfully',
                'data' => [
                    'template' => $template
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create email template',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
