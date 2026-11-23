<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
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
        $this->middleware('permission:notifications.delete')->only(['deleteNotification']);
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
        $contextType = $request->attributes->get('resolved_active_context_type', $request->header('X-Active-Context-Type'));
        if ($contextType === 'internal') {
            $query->where(function ($notifications) {
                $notifications->whereNull('data->type')->orWhere('data->type', '!=', 'corporate_booking_approval_requested');
            });
        } elseif ($contextType) {
            $query->where(function ($notifications) {
                $notifications->whereNull('data->type')->orWhere('data->type', '!=', 'booking_approval_requested');
            });
        }

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $notifications = $query->latest()
            ->paginate($limit);

        $approvalBookingIds = collect($notifications->items())
            ->filter(fn ($notification) => in_array(data_get($notification->data, 'type'), [
                'booking_approval_requested',
                'corporate_booking_approval_requested',
            ], true))
            ->map(fn ($notification) => data_get($notification->data, 'data.booking_id'))
            ->filter()
            ->unique()
            ->values();
        if ($approvalBookingIds->isNotEmpty()) {
            $approvalBookings = Booking::query()
                ->with('corporateAccount:id,name')
                ->whereIn('id', $approvalBookingIds)
                ->get(['id', 'corporate_account_id', 'status', 'requires_approval', 'approval_status', 'corporate_approval_status'])
                ->keyBy(fn (Booking $booking) => (string) $booking->id);
            $pendingApprovals = BookingApproval::query()
                ->whereIn('booking_id', $approvalBookingIds)
                ->where('status', BookingApproval::STATUS_PENDING)
                ->latest()
                ->get()
                ->unique('booking_id')
                ->keyBy(fn (BookingApproval $approval) => (string) $approval->booking_id);

            $notifications->getCollection()->transform(function ($notification) use ($approvalBookings, $pendingApprovals) {
                $data = $notification->data;
                $bookingId = (string) data_get($data, 'data.booking_id', '');
                $booking = $approvalBookings->get($bookingId);
                if (!$booking) {
                    return $notification;
                }

                if ($booking->corporateAccount?->name) {
                    $data['corporate_name'] = $booking->corporateAccount->name;
                }

                if (data_get($data, 'type') === 'corporate_booking_approval_requested') {
                    $resolved = !empty($data['resolved']) || $booking->corporate_approval_status !== 'pending';
                    $data['resolved'] = $resolved;
                    if ($resolved) {
                        $reference = data_get($data, 'data.booking_number') ?: $booking->booking_number ?: $bookingId;
                        $approvalStatus = ucwords(str_replace('_', ' ', (string) $booking->corporate_approval_status));
                        $data['title'] = "Booking #{$reference} · {$approvalStatus}";
                        $data['message'] = "Corporate approval is {$approvalStatus}. This approval request is closed.";
                        $notification->forceFill([
                            'data' => $data,
                            'read_at' => $notification->read_at ?? now(),
                        ])->save();
                    } elseif ($data !== $notification->data) {
                        $notification->forceFill(['data' => $data])->save();
                    }

                    return $notification;
                }

                $pendingApproval = $pendingApprovals->get($bookingId);
                $staffApprovalPending = $booking->status === 'pending_approval'
                    && (bool) $booking->requires_approval
                    && in_array($booking->approval_status, ['pending', 'required'], true)
                    && $booking->corporate_approval_status !== 'pending'
                    && $pendingApproval !== null;
                $notificationApprovalId = (string) data_get($data, 'data.approval_id', '');
                $notificationBelongsToCurrentRequest = $pendingApproval !== null
                    && ($notificationApprovalId !== ''
                        ? $notificationApprovalId === (string) $pendingApproval->id
                        : $notification->created_at >= $pendingApproval->created_at);
                $resolved = !empty($data['resolved']) || !$staffApprovalPending || !$notificationBelongsToCurrentRequest;
                $data['resolved'] = $resolved;
                $reference = data_get($data, 'data.booking_number') ?: $booking->booking_number ?: $bookingId;
                $bookingStatus = ucwords(str_replace('_', ' ', (string) $booking->status));
                if (!$staffApprovalPending) {
                    $data['title'] = "Booking #{$reference} · {$bookingStatus}";
                    $data['message'] = "Current booking status: {$bookingStatus}. This approval request is closed.";
                } elseif (!$notificationBelongsToCurrentRequest) {
                    $data['title'] = "Older approval request for booking #{$reference}";
                    $data['message'] = 'A newer staff approval request is now pending.';
                }

                if ($data !== $notification->data || ($resolved && !$notification->read_at)) {
                    $attributes = ['data' => $data];
                    if ($resolved) $attributes['read_at'] = $notification->read_at ?? now();
                    $notification->forceFill($attributes)->save();
                }

                return $notification;
            });
        }

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
