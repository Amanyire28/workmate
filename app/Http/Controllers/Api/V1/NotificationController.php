<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Services\UserNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in account's notification list (the bell) and its choices */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    /** @var UserNotifications */
    private $notifications;

    public function __construct(UserNotifications $notifications)
    {
        $this->notifications = $notifications;
    }

    /** Newest first */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['cursor' => ['nullable', 'string', 'max:500']]);
        $user = $request->user();

        $page = UserNotification::where('user_id', $user->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(function (UserNotification $n) {
                return [
                    'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
                    'read' => $n->read_at !== null, 'at' => $n->created_at->toIso8601String(),
                ];
            })->values(),
            'meta' => ['next_cursor' => optional($page->nextCursor())->encode(), 'unread' => $this->notifications->unreadCount($user)],
        ]);
    }

    /** One notification as read, or all of them when no id is given */
    public function read(Request $request, ?int $notification = null): JsonResponse
    {
        $this->notifications->markRead($request->user(), $notification);

        return response()->json(['data' => ['unread' => $this->notifications->unreadCount($request->user())]]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->notifications->choices($request->user())]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $rules = [];
        foreach (array_keys(UserNotifications::CHOICES) as $kind) {
            $rules[$kind] = ['sometimes', 'boolean'];
        }
        $data = $request->validate($rules);
        $this->notifications->choose($request->user(), array_map('boolval', $data));

        return response()->json(['data' => $this->notifications->choices($request->user()->fresh())]);
    }
}
