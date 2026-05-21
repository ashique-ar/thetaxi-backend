<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Driver\DriverAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Driver mobile notification inbox controller.
 *
 * Provides driver-scoped notification management for the mobile app without
 * requiring admin notification permissions.
 */
class NotificationController extends Controller
{
    public function __construct(
        private DriverAuthService $authService
    ) {}

    /**
     * List notifications for the authenticated driver's user account.
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());

        if (!$driver) {
            return $this->notDriverResponse();
        }

        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'unread_only' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'max:100'],
        ]);

        $query = $request->user()->notifications();

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        if (!empty($validated['type'])) {
            $query->where('data->notification_type', $validated['type']);
        }

        $notifications = $query
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'status' => 'success',
            'data' => collect($notifications->items())
                ->map(fn ($notification) => $this->formatNotification($notification))
                ->values(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Show a single notification owned by the authenticated driver user.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());

        if (!$driver) {
            return $this->notDriverResponse();
        }

        $notification = $request->user()->notifications()->find($id);

        if (!$notification) {
            return $this->notFoundResponse();
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->formatNotification($notification),
        ]);
    }

    /**
     * Get unread notification count for the authenticated driver user.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());

        if (!$driver) {
            return $this->notDriverResponse();
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    /**
     * Mark one notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());

        if (!$driver) {
            return $this->notDriverResponse();
        }

        $notification = $request->user()->notifications()->find($id);

        if (!$notification) {
            return $this->notFoundResponse();
        }

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification marked as read',
            'data' => $this->formatNotification($notification->fresh()),
        ]);
    }

    /**
     * Mark all driver notifications as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());

        if (!$driver) {
            return $this->notDriverResponse();
        }

        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'status' => 'success',
            'message' => 'All notifications marked as read',
            'data' => [
                'unread_count' => 0,
            ],
        ]);
    }

    /**
     * Delete one notification owned by the authenticated driver user.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $driver = $this->authService->getDriver($request->user());

        if (!$driver) {
            return $this->notDriverResponse();
        }

        $notification = $request->user()->notifications()->find($id);

        if (!$notification) {
            return $this->notFoundResponse();
        }

        $notification->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Notification deleted',
        ]);
    }

    private function formatNotification($notification): array
    {
        $data = $notification->data ?? [];

        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'notification_type' => $data['notification_type'] ?? $data['event_type'] ?? null,
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,
            'data' => $data['data'] ?? $data,
            'read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'updated_at' => $notification->updated_at?->toIso8601String(),
        ];
    }

    private function notDriverResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'User is not registered as a driver',
            'error_code' => 'NOTIFICATION_NOT_DRIVER',
        ], 403);
    }

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Notification not found',
            'error_code' => 'NOTIFICATION_NOT_FOUND',
        ], 404);
    }
}
