<?php

namespace App\Services;

use App\Models\CommunityPost;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What WorkMate suggests to someone in the app (config/engagement.php):
 * open jobs that suit an approved provider, and recent community posts on
 * their topics. Shown when people open the app; never pushed or emailed.
 */
class Recommendations
{
    /** @var JobAlerts */
    private $alerts;

    public function __construct(JobAlerts $alerts)
    {
        $this->alerts = $alerts;
    }

    /**
     * Open jobs in an approved provider's professions, where they work, not
     * their own and not applied to yet: closest first, then newest.
     *
     * @return Collection<int, ServiceRequest>
     */
    public function jobsFor(User $user, ?int $limit = null): Collection
    {
        $profile = $user->providerProfile;
        if (! $profile || $profile->status !== ProviderProfile::STATUS_APPROVED || $profile->is_sample) {
            return collect();
        }
        $config = config('engagement.recommendations');
        $categories = $profile->professions()->pluck('category_id')->unique();
        $applied = Quotation::where('provider_profile_id', $profile->id)->pluck('service_request_id');

        return ServiceRequest::openJobs()
            ->with(['category', 'area', 'photos'])->withCount('quotations')
            ->whereIn('category_id', $categories)
            ->where('customer_id', '!=', $user->id)
            ->whereNotIn('id', $applied->all() ?: [0])
            ->where('created_at', '>=', now()->subDays((int) $config['job_days']))
            ->latest()->limit(60)->get()
            ->map(function (ServiceRequest $job) use ($profile) {
                $km = $this->alerts->reach($profile, $job);

                return $km === null ? null : ['job' => $job, 'km' => $km];
            })
            ->filter()
            ->sortBy(function ($row) {
                return [round($row['km']), -$row['job']->created_at->timestamp];
            })
            ->take($limit ?? (int) $config['jobs'])
            ->pluck('job')->values();
    }

    /**
     * Recent posts on their topics (their professions, or what they've asked
     * for), not their own. Only a first name and a short excerpt.
     */
    public function postsFor(User $user): array
    {
        $config = config('engagement.recommendations');
        $profile = $user->providerProfile;
        $topics = $profile && $profile->status === ProviderProfile::STATUS_APPROVED
            ? $profile->professions()->pluck('category_id')
            : ServiceRequest::where('customer_id', $user->id)->latest()->limit(20)->pluck('category_id');
        if ($topics->isEmpty()) {
            return [];
        }

        return CommunityPost::where('status', CommunityPost::STATUS_PUBLISHED)
            ->whereIn('topic_category_id', $topics->unique()->all())
            ->where('user_id', '!=', $user->id)
            ->where('created_at', '>=', now()->subDays((int) $config['post_days']))
            ->with('author:id,name')
            ->orderByDesc('likes_count')->orderByDesc('id')
            ->limit((int) $config['posts'])->get()
            ->map(function (CommunityPost $p) {
                return [
                    'id' => $p->uuid,
                    'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $p->body)), 0, 140),
                    'author' => $p->author ? explode(' ', trim($p->author->name))[0] : 'A member',
                    'created_at' => $p->created_at->toIso8601String(),
                    'url' => '/community/post/'.$p->uuid,
                ];
            })->values()->all();
    }
}
