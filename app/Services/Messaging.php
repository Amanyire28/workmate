<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Customer–provider chats. Only the customer and the provider's members
 * can read or write a conversation.
 */
class Messaging
{
    /** The customer's chat with a provider, created on first use. */
    public function conversationWith(User $customer, ProviderProfile $provider): Conversation
    {
        if ($provider->hasMember($customer)) {
            throw new BusinessRuleException('own_provider', 'You can’t message your own provider profile.', 422);
        }

        $keys = ['customer_id' => $customer->getKey(), 'provider_profile_id' => $provider->getKey()];
        $conversation = Conversation::where($keys)->first();

        if (! $conversation) {
            $conversation = new Conversation();
            $conversation->forceFill($keys)->save();
        }

        return $conversation;
    }

    public function send(Conversation $conversation, User $sender, string $body): Message
    {
        $this->guardParticipant($conversation, $sender);

        return DB::transaction(function () use ($conversation, $sender, $body) {
            $message = new Message(['body' => $body]);
            $message->forceFill([
                'conversation_id' => $conversation->getKey(),
                'sender_id' => $sender->getKey(),
            ])->save();

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });
    }

    /** Marks the other side's messages as read by this user. */
    public function markRead(Conversation $conversation, User $reader): void
    {
        $this->guardParticipant($conversation, $reader);

        $conversation->messages()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $reader->getKey())
            ->update(['read_at' => now()]);
    }

    public function unreadCount(User $user): int
    {
        return Message::query()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->getKey())
            ->whereIn('conversation_id', Conversation::forParticipant($user)->select('id'))
            ->count();
    }

    public function guardParticipant(Conversation $conversation, User $user): void
    {
        // Reported as "not found" so outsiders can't tell the chat exists
        if (! $conversation->hasParticipant($user)) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
    }
}
