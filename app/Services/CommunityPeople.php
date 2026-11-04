<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\CommunityPostResource;
use App\Models\Category;
use App\Models\CommunityPost;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * People in the community: who can be found and followed, their public
 * cards, follows, suggestions and the professions people post about most.
 * Only listed providers and members who have posted can be found, so an
 * account that never took part stays out of sight.
 */
class CommunityPeople
{
    /** @var EventNotifications */
    private $notify;

    public function __construct(EventNotifications $notify)
    {
        $this->notify = $notify;
    }

    /** Active accounts that are listed providers or have a visible post */
    public function discoverable(): Builder
    {
        return User::query()->where('users.status', User::STATUS_ACTIVE)->where(function ($q) {
            $q->whereHas('providerProfile', function ($p) {
                $p->where('status', ProviderProfile::STATUS_APPROVED);
            })->orWhereHas('communityPosts', function ($p) {
                $p->where('status', CommunityPost::STATUS_PUBLISHED);
            });
        });
    }

    public function isDiscoverable(User $user): bool
    {
        return $this->discoverable()->whereKey($user->getKey())->exists();
    }

    /** Relations and counts a person card needs */
    public function withCard(Builder $query): Builder
    {
        return $query->with(['providerProfile.owner', 'providerProfile.professions', 'providerProfile.baseArea'])
            ->withCount(['followers', 'following', 'communityPosts as posts_count' => function ($p) {
                $p->where('status', CommunityPost::STATUS_PUBLISHED);
            }]);
    }

    /**
     * What anyone may see about a member. No contact details or account fields.
     *
     * @param  array<int, bool>|Collection  $followed  ids the viewer follows
     */
    public function card(User $user, ?User $viewer, $followed = [], bool $linkable = true): array
    {
        $profile = $user->providerProfile;
        $author = CommunityPostResource::author($user, $profile);
        $listed = $author['provider_id'] !== null;

        return $author + [
            'id' => $user->id,
            'area' => $listed && $profile->baseArea ? $profile->baseArea->name : null,
            'followers_count' => (int) ($user->followers_count ?? 0),
            'following_count' => (int) ($user->following_count ?? 0),
            'posts_count' => (int) ($user->posts_count ?? 0),
            'following' => isset($followed[$user->id]),
            'me' => $viewer && $viewer->getKey() === $user->id,
            // Followers who never posted have no page of their own
            'linkable' => $linkable,
        ];
    }

    /** @return Collection<int, true> ids of the people the viewer follows, as keys */
    public function followedBy(?User $viewer, ?array $among = null): Collection
    {
        if (! $viewer) {
            return collect();
        }
        $q = DB::table('follows')->where('follower_id', $viewer->getKey());
        if ($among !== null) {
            $q->whereIn('followed_id', $among ?: [0]);
        }

        return $q->pluck('followed_id')->flip()->map(function () {
            return true;
        });
    }

    /**
     * People to discover: optionally matching a name or a profession, people the
     * viewer already follows left out, those who posted most recently first.
     */
    public function discover(?User $viewer, ?string $search, ?string $topic, bool $excludeFollowed = true): Builder
    {
        $query = $this->withCard($this->discoverable());
        if ($viewer) {
            $query->whereKeyNot($viewer->getKey());
            if ($excludeFollowed) {
                $query->whereNotIn('users.id', DB::table('follows')->where('follower_id', $viewer->getKey())->select('followed_id'));
            }
        }
        if ($search = trim((string) $search)) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)->orWhereHas('providerProfile', function ($p) use ($like) {
                    $p->where('status', ProviderProfile::STATUS_APPROVED)->where('display_name', 'like', $like);
                });
            });
        }
        if ($topic && ($categoryId = Category::where('slug', $topic)->value('id'))) {
            $query->where(function ($q) use ($categoryId) {
                $q->whereHas('providerProfile', function ($p) use ($categoryId) {
                    $p->where('status', ProviderProfile::STATUS_APPROVED)->whereHas('professions', function ($pr) use ($categoryId) {
                        $pr->where('category_id', $categoryId);
                    });
                })->orWhereHas('communityPosts', function ($p) use ($categoryId) {
                    $p->where('status', CommunityPost::STATUS_PUBLISHED)->where('topic_category_id', $categoryId);
                });
            });
        }

        // Most recently active in the community first, then verified providers
        return $query->addSelect(['last_post_at' => CommunityPost::select('created_at')->whereColumn('user_id', 'users.id')
            ->where('status', CommunityPost::STATUS_PUBLISHED)->whereNull('deleted_at')->latest()->limit(1)])
            ->orderByDesc('last_post_at')->orderByDesc('users.id');
    }

    /** A few people worth following: in the viewer's profession first, then anyone */
    public function suggestions(?User $viewer, int $limit = 4): Collection
    {
        $topic = null;
        if ($viewer && $viewer->providerProfile && ($id = $viewer->providerProfile->mainCategoryId())) {
            $topic = Category::whereKey($id)->value('slug');
        }
        $people = $topic ? $this->discover($viewer, null, $topic)->limit($limit)->get() : collect();
        if ($people->count() < $limit) {
            $people = $people->concat($this->discover($viewer, null, null)->whereNotIn('users.id', $people->pluck('id')->all() ?: [0])->limit($limit - $people->count())->get());
        }

        return $people;
    }

    /** The professions people posted about most in the last week, with real counts */
    public function activeTopics(int $limit = 6): Collection
    {
        $counts = CommunityPost::visible()->where('created_at', '>=', now()->subDays(7))->whereNotNull('topic_category_id')
            ->select('topic_category_id', DB::raw('count(*) as posts'))->groupBy('topic_category_id')
            ->orderByDesc('posts')->limit($limit)->pluck('posts', 'topic_category_id');
        $names = Category::whereIn('id', $counts->keys())->get(['id', 'slug', 'name'])->keyBy('id');

        return $counts->map(function ($posts, $id) use ($names) {
            return $names->has($id) ? ['slug' => $names[$id]->slug, 'name' => $names[$id]->name, 'posts' => (int) $posts] : null;
        })->filter()->values();
    }

    /** @return int the followed person's follower count afterwards */
    public function follow(User $viewer, User $person, bool $on): int
    {
        if (! $viewer->hasVerifiedEmail()) {
            throw new BusinessRuleException('email_not_verified', 'Confirm your email address to take part in the community.', 403);
        }
        if ($viewer->getKey() === $person->getKey()) {
            throw new BusinessRuleException('own_account', 'You can’t follow yourself.', 422);
        }

        $where = ['follower_id' => $viewer->getKey(), 'followed_id' => $person->getKey()];
        if ($on) {
            if (! $this->isDiscoverable($person)) {
                throw new BusinessRuleException('person_not_found', 'This person isn’t in the community.', 404);
            }
            // Only a real change counts, so tapping twice can't follow twice
            if (DB::table('follows')->insertOrIgnore($where + ['created_at' => now()])) {
                // Following, unfollowing and following again doesn't keep notifying them
                if (Cache::add('community:followed:'.$viewer->getKey().':'.$person->getKey(), 1, now()->addDays(7))) {
                    $this->notify->newFollower($viewer, $person->getKey());
                }
            }
        } else {
            DB::table('follows')->where($where)->delete();
        }

        return DB::table('follows')->where('followed_id', $person->getKey())->count();
    }
}
