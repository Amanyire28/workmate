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
 * The notifications WorkMate sends by itself when something happens to
 * someone: requests and replies, messages, community activity, and decisions
 * on their application or documents. The wording lives here, in one place;
 * UserNotifications puts each one in the account's list (the bell) and sends
 * it as a push, for the kinds the account hasn't turned off.
 *
 * Notifications never include message text, contact details or reasons, so
 * nothing private shows on a locked screen; the person opens WorkMate to read
 * them. The account that caused the event is never notified about it.
 * "important" ones stay on screen until dismissed where the device allows.
 */
class EventNotifications
{
    /** @var UserNotifications */
    private $notifications;

    public function __construct(UserNotifications $notifications)
    {
        $this->notifications = $notifications;
    }

    /* ---------------- Requests sent to one provider ---------------- */

    public function requestReceived(ServiceRequest $request): void
    {
        $this->toProvider('requests', $request->provider_profile_id, $request->customer_id, [
            'title' => 'New request',
            'body' => $this->firstName($request->customer).' needs '.$this->service($request).'. Reply in WorkMate.',
            'url' => '/activity?tab=received',
            'tag' => 'request-'.$request->id,
            'important' => true,
        ]);
    }

    public function requestAccepted(ServiceRequest $request): void
    {
        // A booking for a time: say which; otherwise as before
        if ($request->status === ServiceRequest::STATUS_SCHEDULED && $request->scheduled_start_at) {
            $this->toCustomer($request, 'Booking confirmed', $this->providerName($request).' confirmed your '.$this->service($request).' booking for '.$this->when($request->scheduled_start_at).'.');

            return;
        }
        $this->toCustomer($request, 'Request accepted', $this->providerName($request).' accepted your '.$this->service($request).' request.');
    }

    public function requestStarted(ServiceRequest $request): void
    {
        $this->toCustomer($request, 'Work in progress', $this->providerName($request).' has started your '.$this->service($request).' job.');
    }

    /** The provider suggested another time; the customer accepts it or not */
    public function timeProposed(ServiceRequest $request): void
    {
        $this->toCustomer($request, 'Time change proposed', $this->providerName($request).' suggested '.$this->when($request->proposed_start_at).' for your '.$this->service($request).' request. Accept it or keep looking.');
    }

    public function proposalAnswered(ServiceRequest $request, bool $accepted): void
    {
        $this->toProvider('requests', $request->provider_profile_id, $request->customer_id, [
            'title' => $accepted ? 'Booking confirmed' : 'Time not accepted',
            'body' => $accepted
                ? $this->firstName($request->customer).' accepted your suggested time: '.$this->when($request->scheduled_start_at).'.'
                : $this->firstName($request->customer).' can’t make the time you suggested.',
            'url' => '/activity?tab=received',
            'tag' => 'request-'.$request->id,
            'important' => true,
        ]);
    }

    /** A request or booking was cancelled by the other side */
    public function requestCancelled(ServiceRequest $request, string $by): void
    {
        if ($by === 'customer') {
            $this->toProvider('requests', $request->provider_profile_id, $request->customer_id, [
                'title' => 'Request cancelled',
                'body' => $this->firstName($request->customer).' cancelled their '.$this->service($request).' request.',
                'url' => '/activity?tab=received',
                'tag' => 'request-'.$request->id,
                'important' => true,
            ]);

            return;
        }
        $this->toCustomer($request, 'Booking cancelled', $this->providerName($request).' had to cancel your '.$this->service($request).' booking. See their reply.');
    }

    /** "Friday 20 Nov, 10:00 AM" in Uganda time */
    private function when($at): string
    {
        return $at ? $at->copy()->timezone('Africa/Kampala')->format('l j M, g:i A') : 'another time';
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

        $this->send('requests', [$job->customer_id], [
            'title' => 'New application',
            'body' => optional($quotation->provider)->display_name.' applied for “'.$job->title.'”.',
            'url' => '/activity?tab=posted',
            'tag' => 'job-'.$job->id,
            'important' => true,
        ]);
    }

    /** A new job a provider could take (JobAlerts chooses who); the job is public, so its title can show */
    public function jobPosted(ServiceRequest $job, iterable $userIds): void
    {
        $this->send('job_alerts', $userIds, [
            'title' => 'New '.$this->service($job).' job near you',
            'body' => '“'.mb_substr($job->title, 0, 80).'” in '.$job->area_name.'. Apply in WorkMate.',
            'url' => '/job/'.$job->id,
            'tag' => 'job-alert-'.$job->id,
        ]);
    }

    public function hired(ServiceRequest $job, Quotation $quotation): void
    {
        $this->toProvider('requests', $quotation->provider_profile_id, $job->customer_id, [
            'title' => 'You got the job',
            'body' => $this->firstName($job->customer).' hired you for “'.$job->title.'”. Message them to agree the details.',
            'url' => '/activity?tab=received',
            'tag' => 'job-'.$job->id,
            'important' => true,
        ]);
    }

    public function notChosen(ServiceRequest $job, Quotation $quotation): void
    {
        $this->toProvider('requests', $quotation->provider_profile_id, $job->customer_id, [
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
            $this->toProvider('messages', $conversation->provider_profile_id, $sender->getKey(), $notice);
        } else {
            $this->send('messages', [$conversation->customer_id], $notice);
        }
    }

    /* ---------------- Provider application ---------------- */

    public function applicationDecided(ProviderApplication $application): void
    {
        $profile = $application->profile;
        $texts = [
            ProviderApplication::STATUS_APPROVED => ['You’re live on WorkMate', 'Your provider profile is approved. Customers can now find and hire you.'],
            ProviderApplication::STATUS_REJECTED => ['Application not approved', 'Your provider application wasn’t approved. See the reason in WorkMate.'],
            ProviderApplication::STATUS_MORE_INFO_REQUIRED => ['Changes needed', 'WorkMate asked you to fix something before approving you. See what to change.'],
        ];
        if (! isset($texts[$application->status])) {
            return;
        }

        $this->send('account', [$profile->owner_id], [
            'title' => $texts[$application->status][0],
            'body' => $texts[$application->status][1],
            'url' => '/start-earning',
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

        $this->send('account', [$verification->profile->owner_id], [
            'title' => $texts[$event][0],
            'body' => $texts[$event][1],
            'url' => '/start-earning',
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

        $this->send('account', [$report->reporter_id], [
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
        $this->send('account', [$warning->user_id], [
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
        $this->send('community_follows', [$followedId], [
            'title' => 'New follower',
            'body' => $this->communityName($follower).' started following you on WorkMate.',
            'url' => '/community/people/'.$follower->id,
            'tag' => 'community-followers',
        ]);
    }

    /** Someone you follow shared a post (Community::publish limits how often) */
    public function followedPosted(CommunityPost $post, User $author, iterable $followerIds): void
    {
        $this->send('community_posts', $followerIds, [
            'title' => 'New post from '.$this->communityName($author),
            'body' => $this->communityName($author).' shared a new post. See it in the community.',
            'url' => '/community/post/'.$post->uuid,
            'tag' => 'community-author-'.$author->id,
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
            $this->send('community_comments', [$toParent], ['title' => 'New reply', 'body' => $name.' replied to your comment.', 'url' => $url, 'tag' => $tag.'-reply']);
        }
        if ($post->user_id !== $by->id && $post->user_id !== $toParent) {
            $this->send('community_comments', [$post->user_id], [
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
        $this->send('community_likes', [$post->user_id], [
            'title' => 'Your post was liked',
            'body' => $this->communityName($by).($others > 0 ? ' and '.$others.' '.($others === 1 ? 'other' : 'others') : '').' liked your post.',
            'url' => '/community/post/'.$post->uuid,
            'tag' => 'community-post-'.$post->uuid.'-likes',
        ]);
    }

    /** The reason is shown on the post in WorkMate, not on the lock screen */
    public function communityContentRemoved(int $userId, string $what, string $url): void
    {
        $this->send('account', [$userId], [
            'title' => 'Your '.$what.' was removed',
            'body' => 'WorkMate removed your '.$what.' from the community. See why in WorkMate.',
            'url' => $url,
            'tag' => 'community-removed-'.$what.'-'.md5($url),
        ]);
    }

    /* ---------------- Invite Friends ---------------- */

    public function referralQualified(int $inviterId, string $name, int $coins = 0): void
    {
        $this->send('referrals', [$inviterId], [
            'title' => $name.' joined WorkMate',
            'body' => $coins > 0
                ? 'Someone you invited confirmed their account. You earned '.number_format($coins).' coins.'
                : 'Someone you invited confirmed their account. Thank you for helping the community grow.',
            'url' => '/account/invite',
            'tag' => 'referral-qualified',
        ]);
    }

    public function badgeEarned(int $userId, string $badge): void
    {
        $this->send('referrals', [$userId], [
            'title' => 'You earned the '.$badge.' badge',
            'body' => 'A thank-you for inviting new members to WorkMate. See it in Invite Friends.',
            'url' => '/account/invite',
            'tag' => 'badge-earned',
        ]);
    }

    public function referralRevoked(int $inviterId): void
    {
        $this->send('account', [$inviterId], [
            'title' => 'An invitation no longer counts',
            'body' => 'After a review, one of your invitations was removed from your count, with its coins. See Invite Friends.',
            'url' => '/account/invite',
            'tag' => 'referral-revoked',
        ]);
    }

    public function badgeRevoked(int $userId, string $badge): void
    {
        $this->send('account', [$userId], [
            'title' => 'Your '.$badge.' badge was removed',
            'body' => 'Your invitation count no longer reaches it. See Invite Friends.',
            'url' => '/account/invite',
            'tag' => 'badge-revoked',
        ]);
    }

    public function workRemoved(int $userId, string $url): void
    {
        $this->send('account', [$userId], [
            'title' => 'Some of your work was removed',
            'body' => 'WorkMate removed a job from your Recent work. See why on your profile.',
            'url' => $url,
            'tag' => 'work-removed-'.md5($url),
        ]);
    }

    /* ---------------- Helpers ---------------- */

    private function send(string $kind, iterable $userIds, array $message): void
    {
        $this->notifications->send($kind, $userIds, $message);
    }

    private function toCustomer(ServiceRequest $request, string $title, string $body): void
    {
        $this->send('requests', [$request->customer_id], [
            'title' => $title,
            'body' => $body,
            'url' => '/activity?tab=requests',
            'tag' => 'request-'.$request->id,
            'important' => true,
        ]);
    }

    /** Everyone in the provider business, except whoever caused the event */
    private function toProvider(string $kind, ?int $providerId, ?int $exceptUserId, array $notice): void
    {
        if (! $providerId) {
            return;
        }

        $members = ProviderMember::where('provider_profile_id', $providerId)->pluck('user_id')
            ->reject(function ($id) use ($exceptUserId) {
                return (int) $id === (int) $exceptUserId;
            });

        $this->send($kind, $members, $notice);
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
