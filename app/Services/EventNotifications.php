<?php

namespace App\Services;

use App\Http\Resources\CommunityPostResource;
use App\Models\AccountWarning;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\Conversation;
use App\Models\DocumentVerification;
use App\Models\Message;
use App\Models\ProviderApplication;
use App\Models\ProviderMember;
use App\Models\Quotation;
use App\Models\Report;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * The push notifications WorkMate sends by itself when something important
 * happens to someone: requests and replies, messages, and decisions on their
 * application or documents. The wording lives here, in one place.
 *
 * Notifications never include message text, contact details or reasons, so
 * nothing private shows on a locked screen; the person opens WorkMate to read
 * them. The account that caused the event is never notified about it.
 * "important" ones stay on screen until dismissed where the device allows.
 */
class EventNotifications
{
    /** @var PushNotifications */
    private $push;

    public function __construct(PushNotifications $push)
    {
        $this->push = $push;
    }

    /* ---------------- Requests sent to one provider ---------------- */

    public function requestReceived(ServiceRequest $request): void
    {
        $this->toProvider($request->provider_profile_id, $request->customer_id, [
            'title' => 'New request',
            'body' => $this->firstName($request->customer).' needs '.$this->service($request).'. Reply in WorkMate.',
            'url' => '/activity?tab=received',
            'tag' => 'request-'.$request->id,
            'important' => true,
        ]);
    }

    public function requestAccepted(ServiceRequest $request): void
    {
        $this->toCustomer($request, 'Request accepted', $this->providerName($request).' accepted your '.$this->service($request).' request.');
    }

    public function requestDeclined(ServiceRequest $request): void
    {
        $this->toCustomer($request, 'Request declined', $this->providerName($request).' can’t take your '.$this->service($request).' request. See their reply.');
    }

    public function requestCompleted(ServiceRequest $request): void
    {
        $this->toCustomer($request, 'Your job is done', $this->providerName($request).' finished your '.$this->service($request).' job. How did it go? Rate them in WorkMate.');
    }

    /* ---------------- Posted jobs ---------------- */

    public function applicationReceived(Quotation $quotation): void
    {
        $job = $quotation->request;

        $this->push->toUsers([$job->customer_id], [
            'title' => 'New application',
            'body' => optional($quotation->provider)->display_name.' applied for “'.$job->title.'”.',
            'url' => '/activity?tab=posted',
            'tag' => 'job-'.$job->id,
            'important' => true,
        ]);
    }

    public function hired(ServiceRequest $job, Quotation $quotation): void
    {
        $this->toProvider($quotation->provider_profile_id, $job->customer_id, [
            'title' => 'You got the job',
            'body' => $this->firstName($job->customer).' hired you for “'.$job->title.'”. Message them to agree the details.',
            'url' => '/activity?tab=received',
            'tag' => 'job-'.$job->id,
            'important' => true,
        ]);
    }

    public function notChosen(ServiceRequest $job, Quotation $quotation): void
    {
        $this->toProvider($quotation->provider_profile_id, $job->customer_id, [
            'title' => 'Job filled',
            'body' => 'The customer chose someone else for “'.$job->title.'”. Keep applying: new jobs are posted every day.',
            'url' => '/find-work',
            'tag' => 'job-'.$job->id,
        ]);
    }

    /* ---------------- Messages ---------------- */

    public function messageSent(Conversation $conversation, Message $message, User $sender): void
    {
        $fromCustomer = $conversation->isCustomer($sender);
        $name = $fromCustomer ? $this->firstName($sender) : $conversation->provider->display_name;
        $notice = [
            'title' => 'New message',
            'body' => $name.' sent you a message.',
            'url' => '/messages/c/'.$conversation->id,
            // One notification per chat, replaced by the newest
            'tag' => 'chat-'.$conversation->id,
        ];

        if ($fromCustomer) {
            $this->toProvider($conversation->provider_profile_id, $sender->getKey(), $notice);
        } else {
            $this->push->toUsers([$conversation->customer_id], $notice);
        }
    }

    /* ---------------- Provider application ---------------- */

    public function applicationDecided(ProviderApplication $application): void
    {
        $profile = $application->profile;
        $texts = [
            ProviderApplication::STATUS_APPROVED => ['You’re approved', 'Your WorkMate provider profile is live. Customers can now find and hire you.'],
            ProviderApplication::STATUS_REJECTED => ['Application not approved', 'Your provider application wasn’t approved. See the reason in WorkMate.'],
            ProviderApplication::STATUS_MORE_INFO_REQUIRED => ['Changes needed', 'WorkMate needs more information on your provider application.'],
        ];
        if (! isset($texts[$application->status])) {
            return;
        }

        $this->push->toUsers([$profile->owner_id], [
            'title' => $texts[$application->status][0],
            'body' => $texts[$application->status][1],
            'url' => '/account',
            'tag' => 'provider-application',
            'important' => true,
        ]);
    }

    /* ---------------- Document verification ---------------- */

    /** @param string $event approved | rejected | sent_back | requested | info_requested */
    public function verificationChanged(DocumentVerification $verification, string $event): void
    {
        $texts = [
            'approved' => ['Documents approved', 'Your documents are approved. Your profile now shows the tick.'],
            'rejected' => ['Documents not approved', 'Your documents weren’t approved. See the reason in WorkMate.'],
            'sent_back' => ['Document sent back', 'A document needs replacing. See what to change in WorkMate.'],
            'requested' => ['Another document needed', 'WorkMate asked you for another document.'],
            'info_requested' => ['More information needed', 'WorkMate needs more information about your documents.'],
        ];
        if (! isset($texts[$event])) {
            return;
        }

        $this->push->toUsers([$verification->profile->owner_id], [
            'title' => $texts[$event][0],
            'body' => $texts[$event][1],
            'url' => '/account/verification',
            'tag' => 'verification',
            'important' => true,
        ]);
    }

    /* ---------------- Reports and warnings ---------------- */

    /** @param string $event info_requested | resolved */
    public function reportChanged(Report $report, string $event): void
    {
        $texts = [
            'info_requested' => ['WorkMate has a question', 'We need a little more information about your report.'],
            'resolved' => ['Your report has been reviewed', 'WorkMate has finished looking into your report. See the outcome.'],
        ];
        if (! isset($texts[$event]) || ! $report->reporter_id) {
            return;
        }

        $this->push->toUsers([$report->reporter_id], [
            'title' => $texts[$event][0],
            'body' => $texts[$event][1],
            'url' => '/account/reports',
            'tag' => 'report-'.$report->id,
            'important' => true,
        ]);
    }

    /** The warning's text stays in WorkMate; the notification only says there is one */
    public function warningIssued(AccountWarning $warning): void
    {
        $this->push->toUsers([$warning->user_id], [
            'title' => 'A notice from WorkMate',
            'body' => 'You have a notice about how you use WorkMate. Please read it.',
            'url' => '/account/reports',
            'tag' => 'warning-'.$warning->id,
            'important' => true,
        ]);
    }

    /* ---------------- Community ---------------- */

    // A post's notifications share a tag, so a phone shows the latest one instead of a pile

    public function newFollower(User $follower, int $followedId): void
    {
        $this->push->toUsers([$followedId], [
            'title' => 'New follower',
            'body' => $this->communityName($follower).' started following you on WorkMate.',
            'url' => '/community/people/'.$follower->id,
            'tag' => 'community-followers',
        ]);
    }

    /** A comment on someone's post, or a reply to their comment. The words stay in WorkMate. */
    public function commented(CommunityPost $post, CommunityComment $comment, User $by): void
    {
        $name = $this->communityName($by);
        $url = '/community/post/'.$post->uuid.'#comments';
        $tag = 'community-post-'.$post->uuid;
        $toParent = $comment->parent_id ? optional($comment->parent)->user_id : null;

        if ($toParent && $toParent !== $by->id) {
            $this->push->toUsers([$toParent], ['title' => 'New reply', 'body' => $name.' replied to your comment.', 'url' => $url, 'tag' => $tag.'-reply']);
        }
        if ($post->user_id !== $by->id && $post->user_id !== $toParent) {
            $this->push->toUsers([$post->user_id], [
                'title' => $post->category === 'question' ? 'New answer' : 'New comment',
                'body' => $name.($post->category === 'question' ? ' answered your question.' : ' commented on your post.'),
                'url' => $url, 'tag' => $tag,
            ]);
        }
    }

    /** At most once an hour per post (CommunityPost::likes_notified_at) */
    public function liked(CommunityPost $post, User $by, int $likes): void
    {
        if ($post->user_id === $by->id) {
            return;
        }
        $others = $likes - 1;
        $this->push->toUsers([$post->user_id], [
            'title' => 'Your post was liked',
            'body' => $this->communityName($by).($others > 0 ? ' and '.$others.' '.($others === 1 ? 'other' : 'others') : '').' liked your post.',
            'url' => '/community/post/'.$post->uuid,
            'tag' => 'community-post-'.$post->uuid.'-likes',
        ]);
    }

    /** The reason is shown on the post in WorkMate, not on the lock screen */
    public function communityContentRemoved(int $userId, string $what, string $url): void
    {
        $this->push->toUsers([$userId], [
            'title' => 'Your '.$what.' was removed',
            'body' => 'WorkMate removed your '.$what.' from the community. See why in WorkMate.',
            'url' => $url,
            'tag' => 'community-removed-'.$what.'-'.md5($url),
        ]);
    }

    /* ---------------- Helpers ---------------- */

    private function toCustomer(ServiceRequest $request, string $title, string $body): void
    {
        $this->push->toUsers([$request->customer_id], [
            'title' => $title,
            'body' => $body,
            'url' => '/activity?tab=requests',
            'tag' => 'request-'.$request->id,
            'important' => true,
        ]);
    }

    /** Everyone in the provider business, except whoever caused the event */
    private function toProvider(?int $providerId, ?int $exceptUserId, array $notice): void
    {
        if (! $providerId) {
            return;
        }

        $members = ProviderMember::where('provider_profile_id', $providerId)->pluck('user_id')
            ->reject(function ($id) use ($exceptUserId) {
                return (int) $id === (int) $exceptUserId;
            });

        $this->push->toUsers($members, $notice);
    }

    private function providerName(ServiceRequest $request): string
    {
        return optional($request->provider)->display_name ?: 'The provider';
    }

    private function service(ServiceRequest $request): string
    {
        return mb_strtolower(optional($request->category)->name ?: 'service');
    }

    /** How someone appears in the community: a listed provider's name, or "Aisha N." */
    private function communityName(User $user): string
    {
        return CommunityPostResource::author($user, $user->providerProfile)['name'];
    }

    private function firstName(?User $user): string
    {
        $name = $user ? trim($user->name) : '';

        return $name !== '' ? explode(' ', $name)[0] : 'A customer';
    }
}
