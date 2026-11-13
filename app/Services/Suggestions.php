<?php

namespace App\Services;

use App\Models\CommunityPost;
use App\Models\DocumentVerification;
use App\Models\ProviderProfile;
use App\Models\User;

/**
 * The suggestion cards shown in the body of WorkMate's pages, like "Add your
 * services and prices". Each applies only while its condition holds (worked
 * out from what's actually saved), so it disappears by itself once done.
 * They are never sent as notifications.
 *
 * Each page shows at most one card. A card goes on the first page that wants
 * it, so the same suggestion isn't repeated everywhere (at most two pages).
 */
class Suggestions
{
    public const PAGES = ['account', 'work', 'home', 'provider', 'community'];

    /** Which cards each page can show, most important first (pages in the order they choose) */
    private const PAGE_CARDS = [
        'account' => ['finish_application', 'complete_profile', 'add_photo', 'add_services', 'get_verified'],
        'home' => ['finish_application', 'complete_profile', 'explore_jobs', 'add_services'],
        'work' => ['add_services', 'add_photo', 'get_verified', 'share_work'],
        'provider' => ['add_photo', 'get_verified', 'share_work'],
        'community' => ['share_work'],
    ];

    private const MAX_PAGES_PER_CARD = 2;

    /** @var ProfileCompleteness */
    private $completeness;

    /** @var Recommendations */
    private $recommendations;

    public function __construct(ProfileCompleteness $completeness, Recommendations $recommendations)
    {
        $this->completeness = $completeness;
        $this->recommendations = $recommendations;
    }

    /**
     * @return array{cards: array<string, array>, pages: array<string, ?string>}
     *   the cards that apply, and which one (if any) each page shows
     */
    public function forUser(User $user): array
    {
        $cards = $this->applicable($user);

        $pages = array_fill_keys(self::PAGES, null);
        $placed = [];
        // First, each page takes a card no other page has; then empty pages may repeat one
        foreach ([false, true] as $repeat) {
            foreach (self::PAGES as $page) {
                if ($pages[$page] !== null) {
                    continue;
                }
                foreach (self::PAGE_CARDS[$page] as $key) {
                    $times = $placed[$key] ?? 0;
                    if (isset($cards[$key]) && ($repeat ? $times < self::MAX_PAGES_PER_CARD : $times === 0)) {
                        $pages[$page] = $key;
                        $placed[$key] = $times + 1;
                        break;
                    }
                }
            }
        }

        return ['cards' => $cards, 'pages' => $pages];
    }

    /** @return array<string, array{key: string, icon: string, title: string, body: string, action: array, progress: ?int}> */
    private function applicable(User $user): array
    {
        $state = $this->completeness->forUser($user);
        $profile = $user->providerProfile;
        $cards = [];

        if ($state['applies'] && $state['role'] === ProfileCompleteness::ROLE_CUSTOMER && ! $state['complete']) {
            $next = $state['next'];
            $why = [
                'phone' => 'Add your phone number so providers can reach you about your requests.',
                'email' => 'Confirm your email address so you don’t miss replies from providers.',
                'name' => 'Add your name so providers know who they’re helping.',
            ];
            $cards['complete_profile'] = $this->card('complete_profile', 'bi-person-check', 'Make your profile stand out! ✨',
                $why[$next['key']] ?? 'A complete profile helps providers trust and respond to you.',
                'Complete my profile', $next['url'], $state['percent']);
        }

        if ($state['applies'] && $state['role'] === ProfileCompleteness::ROLE_APPLICANT) {
            $changes = $profile->status === ProviderProfile::STATUS_MORE_INFO_REQUIRED;
            $cards['finish_application'] = $this->card('finish_application', 'bi-briefcase',
                $changes ? 'Update your provider application' : 'Finish your provider application',
                $changes ? 'WorkMate asked for a few changes before approving you. See what to fix.'
                    : ($state['complete'] ? 'Your details are ready. Send your application so WorkMate can review it.'
                        : 'Next: '.mb_strtolower($state['next']['label']).'. Then send it for review.'),
                'Continue my application', '/start-earning', $state['percent']);
        }

        if ($state['role'] === ProfileCompleteness::ROLE_PROVIDER && $profile && ! $profile->is_sample) {
            $missing = collect($state['missing'])->keyBy('key');

            if ($missing->has('services') || $missing->has('price')) {
                $cards['add_services'] = $this->card('add_services', 'bi-tags', 'Add your services and prices',
                    'Customers see what you offer and your usual prices, and can request a service straight from your profile.',
                    'Add services', '/provider/'.$profile->id.'#services');
            }
            if ($missing->has('photo')) {
                $cards['add_photo'] = $this->card('add_photo', 'bi-camera', 'Add a professional profile photo',
                    'A clear photo of your face helps customers trust you and recognise you when you arrive.',
                    'Add photo', '/account#profile-photo');
            }
            if ($verification = $this->verificationToDo($profile)) {
                $cards['get_verified'] = $verification;
            }
            $since = now()->subDays((int) config('engagement.recommendations.post_days'));
            if (! CommunityPost::where('user_id', $user->id)->where('status', CommunityPost::STATUS_PUBLISHED)->where('created_at', '>=', $since)->exists()) {
                $cards['share_work'] = $this->card('share_work', 'bi-images', 'Share your latest work',
                    'Post photos of a recent job in the Community so customers get to know you.',
                    'Share your work', '/community?compose=1');
            }
            $jobs = $this->recommendations->jobsFor($user, 99);
            if ($jobs->isNotEmpty()) {
                $n = $jobs->count();
                $cards['explore_jobs'] = $this->card('explore_jobs', 'bi-search',
                    $n === 1 ? 'A new job matches your skills' : $n.' open jobs match your skills',
                    'Customers near where you work are looking for someone. Apply while they’re open.',
                    'See jobs', '/find-work?category='.$jobs->first()->category->slug);
            }
        }

        return $cards;
    }

    /** Verification, while there's something for the provider to do (not sent, or sent back) */
    private function verificationToDo(ProviderProfile $profile): ?array
    {
        if ($profile->document_verified_at !== null) {
            return null;
        }
        $status = DocumentVerification::where('provider_profile_id', $profile->id)->value('status');
        if ($status === DocumentVerification::STATUS_INFO_REQUESTED) {
            return $this->card('get_verified', 'bi-patch-check', 'Finish your verification',
                'WorkMate needs something more before it can check your documents.', 'Continue', '/account/verification');
        }
        if ($status === null || $status === DocumentVerification::STATUS_DRAFT) {
            return $this->card('get_verified', 'bi-patch-check', 'Get your documents verified',
                'Customers see a verified tick on your profile once WorkMate has checked your ID and documents.', 'Get verified', '/account/verification');
        }

        return null;   // waiting for review, approved or not approved
    }

    private function card(string $key, string $icon, string $title, string $body, string $label, string $url, ?int $progress = null): array
    {
        return ['key' => $key, 'icon' => $icon, 'title' => $title, 'body' => $body, 'action' => ['label' => $label, 'url' => $url], 'progress' => $progress];
    }
}
