<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\Messaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /** @var Messaging */
    private $messaging;

    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /** Chats with at least one message, newest first, on either side */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::forParticipant($user)
            ->whereNotNull('last_message_at')
            ->with(['provider.owner:id,name,avatar_path', 'customer:id,name,avatar_path', 'latestMessage'])
            ->withCount(['messages as unread_count' => function ($query) use ($user) {
                $query->whereNull('read_at')->where('sender_id', '!=', $user->getKey());
            }])
            ->latest('last_message_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $conversations->map(function ($c) use ($user) {
            return $this->summary($c, $user);
        })]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread' => $this->messaging->unreadCount($request->user())]]);
    }

    /** Opens (or starts) the signed-in customer's chat with a provider */
    public function withProvider(Request $request, int $providerId): JsonResponse
    {
        $provider = ProviderProfile::publiclyListed()->findOrFail($providerId);
        $conversation = $this->messaging->conversationWith($request->user(), $provider);

        return $this->detail($conversation, $request->user());
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->messaging->guardParticipant($conversation, $request->user());

        return $this->detail($conversation, $request->user());
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $this->messaging->send($conversation, $request->user(), trim($data['body']));

        return response()->json(['data' => $this->message($message, $request->user())], 201);
    }

    /** Messages, oldest first. Opening the chat marks the other side's messages read. */
    private function detail(Conversation $conversation, User $user): JsonResponse
    {
        $this->messaging->markRead($conversation, $user);
        $conversation->load(['provider.owner:id,name,avatar_path', 'customer:id,name,avatar_path']);

        $messages = $conversation->messages()->latest('id')->limit(200)->get()->reverse()->values();

        return response()->json(['data' => array_merge($this->summary($conversation, $user), [
            'messages' => $messages->map(function ($m) use ($user) {
                return $this->message($m, $user);
            }),
        ])]);
    }

    private function summary(Conversation $c, User $user): array
    {
        $asCustomer = $c->isCustomer($user);
        $last = $c->relationLoaded('latestMessage') ? $c->latestMessage : null;

        return [
            'id' => $c->id,
            // Which side the signed-in user is on
            'role' => $asCustomer ? 'customer' : 'provider',
            'provider_id' => $c->provider_profile_id,
            // Only a first name is shown to providers until a booking exists
            'counterpart_name' => $asCustomer
                ? $c->provider->display_name
                : explode(' ', trim($c->customer->name))[0],
            'counterpart_avatar_url' => $asCustomer ? $c->provider->owner->avatarUrl() : $c->customer->avatarUrl(),
            'is_sample_provider' => $c->provider->is_sample,
            'unread' => (int) ($c->unread_count ?? 0),
            'last_message' => $last ? $this->message($last, $user) : null,
        ];
    }

    private function message(Message $m, User $user): array
    {
        return [
            'id' => $m->id,
            'mine' => $m->sender_id === (int) $user->getKey(),
            'body' => $m->body,
            'sent_at' => $m->created_at->toIso8601String(),
            'read' => $m->read_at !== null,
        ];
    }
}
