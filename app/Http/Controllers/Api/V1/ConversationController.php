<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\Messaging;
use App\Services\RequestDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            ->with(['provider.owner:id,name,avatar_path', 'customer:id,name,avatar_path', 'latestMessage.attachments', 'latestMessage.serviceRequest.category'])
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
        // Outsiders learn nothing, not even which fields were wrong
        $this->messaging->guardParticipant($conversation, $request->user());

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:2000', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:'.MessageAttachment::MAX],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ], [
            'body.required_without' => 'Write a message or add a photo or file.',
            'attachments.max' => 'Send up to '.MessageAttachment::MAX.' photos or files at a time.',
            'attachments.*.mimes' => 'Send photos (JPG, PNG or WebP) or PDF documents.',
            'attachments.*.max' => 'Each photo or file can be up to 10 MB.',
        ]);

        $message = $this->messaging->send($conversation, $request->user(), trim((string) ($data['body'] ?? '')), $request->file('attachments', []));

        return response()->json(['data' => $this->message($message, $request->user())], 201);
    }

    /** A photo or PDF from the chat, for its two sides only */
    public function attachment(Request $request, Conversation $conversation, string $file)
    {
        $this->messaging->guardParticipant($conversation, $request->user());
        abort_unless(preg_match('/^([0-9a-f-]{36})(-t)?\.(jpg|pdf)$/', $file, $m), 404);

        $attachment = MessageAttachment::where('conversation_id', $conversation->id)->where('uuid', $m[1])->first();
        $isPhoto = $attachment && $attachment->kind === MessageAttachment::KIND_PHOTO;
        // The link must match the kind: .jpg (or -t.jpg) for photos, .pdf for documents
        abort_unless($attachment && ($m[3] === 'jpg') === $isPhoto && ($isPhoto || empty($m[2])), 404);

        $path = ! empty($m[2]) ? $attachment->thumb_path : $attachment->path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $headers = ['Content-Type' => $attachment->mime, 'X-Content-Type-Options' => 'nosniff'];
        $response = $isPhoto
            ? response()->file(Storage::disk('local')->path($path), $headers)
            // Documents download rather than open inside the site
            : response()->download(Storage::disk('local')->path($path), $attachment->original_name ?: 'Document.pdf', $headers);
        // Private: never stored by shared caches or kept by the browser
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /** Messages, oldest first. Opening the chat marks the other side's messages read. */
    private function detail(Conversation $conversation, User $user): JsonResponse
    {
        $this->messaging->markRead($conversation, $user);
        $conversation->load(['provider.owner:id,name,avatar_path', 'customer:id,name,avatar_path']);

        $messages = $conversation->messages()->with(['attachments', 'serviceRequest.category', 'serviceRequest.provider:id,display_name'])
            ->latest('id')->limit(200)->get()->reverse()->values();
        $asCustomer = $conversation->isCustomer($user);

        return response()->json(['data' => array_merge($this->summary($conversation, $user), [
            'messages' => $messages->map(function ($m) use ($user, $asCustomer) {
                return $this->message($m, $user, $asCustomer);
            }),
        ])]);
    }

    /**
     * What a request card shows. The customer sees everything; the provider
     * sees the exact location only once they've accepted (RequestDetails).
     */
    private function card(\App\Models\ServiceRequest $r, bool $asCustomer): array
    {
        $details = $asCustomer ? RequestDetails::forCustomer($r) : RequestDetails::forProvider($r);

        return [
            'id' => $r->id,
            'reference' => 'WM-R'.str_pad((string) $r->id, 5, '0', STR_PAD_LEFT),
            'kind' => $r->isPostedJob() ? 'posted' : 'direct',
            'title' => $r->title,
            'category_name' => optional($r->category)->name,
            'description' => $r->description,
            'budget' => $r->budget,
            'budget_basis' => $r->budget_basis,
            'preferred_time' => $r->preferred_time,
            'status' => $r->trashed() ? 'cancelled' : $r->status,
            'schedule' => $details['schedule'],
            'location' => $details['location'],
        ];
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

    private function message(Message $m, User $user, ?bool $asCustomer = null): array
    {
        return [
            'id' => $m->id,
            'mine' => $m->sender_id === (int) $user->getKey(),
            'kind' => $m->kind ?: 'text',
            // A request card: the request as it is now (it stays the one record of the job)
            'request' => $m->service_request_id && $m->serviceRequest ? $this->card($m->serviceRequest, $asCustomer ?? ($m->serviceRequest->customer_id === (int) $user->getKey())) : null,
            'body' => $m->body,
            'attachments' => $m->attachments->map(function (MessageAttachment $a) {
                return $a->present();
            })->values(),
            'sent_at' => $m->created_at->toIso8601String(),
            'read' => $m->read_at !== null,
        ];
    }
}
