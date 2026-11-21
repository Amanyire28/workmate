<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Services\NotificationPresenter;
use App\Services\PushDelivery;
use App\Services\PushNotifications;
use App\Services\UserNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in account's notification list (the bell) and its choices */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    /** @var UserNotifications */
    private $notifications;

    /** @var NotificationPresenter */
    private $presenter;

    public function __construct(UserNotifications $notifications, NotificationPresenter $presenter)
    {
        $this->notifications = $notifications;
        $this->presenter = $presenter;
    }

    /**
     * Newest first. Likes and follows come grouped, with the newest three people
     * (their real names and pictures) and the live count; everything else with the
     * person it is about, where there is one. ?filter=unread lists only unread.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['cursor' => ['nullable', 'string', 'max:500'], 'filter' => ['nullable', 'in:all,unread']]);
        $user = $request->user();

        $query = $this->notifications->visible(UserNotification::where('user_id', $user->getKey()));
        if ($request->query('filter') === 'unread') {
            $query->whereNull('read_at');
        }
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(self::PER_PAGE);

        // Who, what it is about, previews and counts as they are now, read for the whole page at once
        $data = $this->presenter->present(collect($page->items()));

        return response()->json([
            'data' => $data,
            'meta' => ['next_cursor' => optional($page->nextCursor())->encode(), 'unread' => $this->notifications->unreadCount($user)],
        ]);
    }

    /**
     * Who is behind a grouped notification, or which comments it is made of (up to 50, newest first), for
     * the sheet that opens from it. Only the signed-in person's own notification, and only while it can be seen.
     */
    public function activity(Request $request, int $notification): JsonResponse
    {
        $row = $this->notifications->visible(UserNotification::where('user_id', $request->user()->getKey()))->findOrFail($notification);

        return response()->json(['data' => $this->presenter->activity($row)]);
    }

    /** Just the number for the bell (a window asks for it when a push arrives) */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->notifications->unreadCount($request->user())]]);
    }

    /** One notification as read, or all of them when no id is given */
    public function read(Request $request, ?int $notification = null): JsonResponse
    {
        $this->notifications->markRead($request->user(), $notification);

        return response()->json(['data' => ['unread' => $this->notifications->unreadCount($request->user())]]);
    }

    public function settings(Request $request): JsonResponse
    {
        return $this->settingsResponse($request);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $rules = [
            'push' => ['sometimes', 'array'],
            'push.*' => ['boolean'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.enabled' => ['required_with:quiet_hours', 'boolean'],
            'quiet_hours.start' => ['nullable', 'string', 'max:5'],
            'quiet_hours.end' => ['nullable', 'string', 'max:5'],
            'quiet_hours.timezone' => ['nullable', 'string', 'max:40'],
        ];
        foreach (array_keys(UserNotifications::CHOICES) as $kind) {
            $rules[$kind] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $user = $request->user();

        $bell = [];
        foreach (array_keys(UserNotifications::CHOICES) as $kind) {
            if (array_key_exists($kind, $data)) {
                $bell[$kind] = (bool) $data[$kind];
            }
        }
        $push = array_map('boolval', $data['push'] ?? []);
        $this->notifications->choose($user, $bell, $push);
        if (isset($data['quiet_hours'])) {
            $this->notifications->setQuietHours($user->fresh(), $data['quiet_hours']);
        }

        return $this->settingsResponse($request, $user->fresh());
    }

    private function settingsResponse(Request $request, $user = null): JsonResponse
    {
        $user = $user ?: $request->user();

        return response()->json([
            'data' => $this->notifications->choices($user),
            'meta' => [
                'quiet_hours' => $this->notifications->quietHours($user),
                // Quiet hours and grouped pushes work only while the platform has smart push on
                'smart_push' => app(PushDelivery::class)->smartEnabled(),
                'push_available' => PushNotifications::configured(),
            ],
        ]);
    }
}
