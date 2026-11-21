<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Services\EngagementNotifications;
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

    /** @var EngagementNotifications */
    private $engagement;

    public function __construct(UserNotifications $notifications, EngagementNotifications $engagement)
    {
        $this->notifications = $notifications;
        $this->engagement = $engagement;
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
        $items = collect($page->items());

        // People: grouped rows from the likes / follows tables, single ones from the person they name (a few queries for the whole page)
        $people = $this->engagement->peopleFor($items);
        $actors = $this->engagement->users($items->whereNull('group_key')->pluck('actor_id')->filter()->all());

        $data = $items->map(function (UserNotification $n) use ($people, $actors) {
            $grouped = $n->group_key !== null;
            $who = $grouped ? ($people[$n->id] ?? ['count' => 0, 'actors' => []]) : ['count' => null, 'actors' => isset($actors[$n->actor_id]) ? [$actors[$n->actor_id]] : []];
            $title = $n->title;
            $body = $n->body;
            $url = $n->url;
            if ($grouped) {
                if ($who['count'] < 1) {
                    return null;   // everyone in it has since gone
                }
                [$title, $body] = $this->engagement->compose($n->kind, $n->subject_type, array_column(array_slice($who['actors'], 0, 2), 'name'), $who['count']);
                if ($n->kind === EngagementNotifications::FOLLOWS && $who['actors']) {
                    $url = '/community/people/'.$who['actors'][0]['id'];
                }
            }

            return [
                'id' => $n->id, 'kind' => $n->kind, 'priority' => UserNotifications::priority($n->kind),
                'title' => $title, 'body' => $body, 'url' => $url,
                'grouped' => $grouped, 'count' => $who['count'],
                'actors' => array_values($who['actors']),
                'read' => $n->read_at !== null, 'at' => $n->created_at->toIso8601String(),
            ];
        })->filter()->values();

        return response()->json([
            'data' => $data,
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
