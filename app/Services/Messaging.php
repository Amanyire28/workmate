<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Customer–provider chats. Only the customer and the provider's members
 * can read or write a conversation.
 */
class Messaging
{
    /** @var EventNotifications */
    private $notify;

    /** @var PhotoVariants */
    private $photos;

    public function __construct(EventNotifications $notify, PhotoVariants $photos)
    {
        $this->notify = $notify;
        $this->photos = $photos;
    }

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

    /**
     * Sends a message: text, attachments (photos and PDFs) or both.
     *
     * @param UploadedFile[] $files
     */
    public function send(Conversation $conversation, User $sender, string $body, array $files = []): Message
    {
        $this->guardParticipant($conversation, $sender);
        if ($body === '' && ! $files) {
            throw new BusinessRuleException('message_empty', 'Write a message or add a photo or file.', 422);
        }

        // Files are stored first; if anything after fails, they're removed again
        $stored = [];
        try {
            foreach (array_slice($files, 0, MessageAttachment::MAX) as $file) {
                $stored[] = $this->storeFile($file);
            }

            return DB::transaction(function () use ($conversation, $sender, $body, $stored) {
                $message = new Message(['body' => $body]);
                $message->forceFill([
                    'conversation_id' => $conversation->getKey(),
                    'sender_id' => $sender->getKey(),
                ])->save();

                foreach ($stored as $i => $file) {
                    $attachment = new MessageAttachment();
                    $attachment->forceFill($file + ['message_id' => $message->id, 'conversation_id' => $conversation->getKey(), 'position' => $i])->save();
                }

                $conversation->forceFill(['last_message_at' => $message->created_at])->save();
                $this->notify->messageSent($conversation, $message, $sender);

                return $message->load('attachments');
            });
        } catch (\Throwable $e) {
            $this->photos->discard(array_values(array_filter(array_merge(array_column($stored, 'path'), array_column($stored, 'thumb_path')))));
            throw $e;
        }
    }

    /** A photo is re-drawn (dropping hidden data such as GPS); a PDF is kept as sent, after checking it is one */
    private function storeFile(UploadedFile $file): array
    {
        $size = (int) $file->getSize();

        if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            $photo = $this->photos->store($file, MessageAttachment::PHOTO_DIRECTORY);

            return [
                'uuid' => $photo['uuid'], 'kind' => MessageAttachment::KIND_PHOTO, 'path' => $photo['path'], 'thumb_path' => $photo['thumb_path'],
                'original_name' => null, 'mime' => 'image/jpeg', 'size' => $size, 'width' => $photo['width'], 'height' => $photo['height'],
            ];
        }

        $handle = fopen($file->getRealPath(), 'rb');
        $magic = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($magic !== '%PDF-') {
            throw new BusinessRuleException('file_unreadable', 'One of the files isn’t a PDF document. Send photos or PDF files.', 422);
        }

        $uuid = (string) Str::uuid();
        $path = MessageAttachment::FILE_DIRECTORY.'/'.$uuid.'.pdf';
        Storage::disk('local')->putFileAs(MessageAttachment::FILE_DIRECTORY, $file, $uuid.'.pdf');

        return [
            'uuid' => $uuid, 'kind' => MessageAttachment::KIND_FILE, 'path' => $path, 'thumb_path' => null,
            'original_name' => $this->safeName($file->getClientOriginalName()), 'mime' => 'application/pdf', 'size' => $size, 'width' => null, 'height' => null,
        ];
    }

    /** The name shown and used for downloads: plain characters only, ending in .pdf */
    private function safeName(string $name): string
    {
        $base = trim(preg_replace('/[^A-Za-z0-9 ._()-]+/', '', pathinfo($name, PATHINFO_FILENAME)), ' .');

        return Str::limit($base !== '' ? $base : 'Document', 120, '').'.pdf';
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
