<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\CommunityPeople;
use App\Services\Referrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** People in the community: discovering, following and their public cards */
class CommunityPeopleController extends Controller
{
    private const PER_PAGE = 20;

    /** @var CommunityPeople */
    private $people;

    public function __construct(CommunityPeople $people)
    {
        $this->people = $people;
    }

    /** People to follow, or everyone matching a name or profession */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'topic' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $viewer = $request->user('sanctum');
        $search = $data['q'] ?? null;

        // Suggestions leave out people already followed; a search finds them too
        $page = $this->people->discover($viewer, $search, $data['topic'] ?? null, ! $search)->simplePaginate(self::PER_PAGE);

        return response()->json([
            'data' => $this->cards(collect($page->items()), $viewer),
            'meta' => ['next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null],
        ]);
    }

    public function suggested(Request $request): JsonResponse
    {
        $viewer = $request->user('sanctum');

        return response()->json(['data' => $this->cards($this->people->suggestions($viewer), $viewer)]);
    }

    public function show(Request $request, int $user): JsonResponse
    {
        $viewer = $request->user('sanctum');
        // Members can always open their own page, even before they've posted
        $person = $viewer && $viewer->getKey() === $user
            ? $this->people->withCard(User::query())->findOrFail($user)
            : $this->findPerson($user);

        $card = $this->cards(collect([$person]), $viewer)[0];
        // Invite Friends recognition: thanks for bringing members, not a sign of skill or verification
        $card['badges'] = app(Referrals::class)->heldBadges($person)->map(function ($b) {
            return Referrals::presentBadge($b);
        })->values();

        return response()->json(['data' => $card]);
    }

    /** The community card of a listed provider's owner, for their profile page */
    public function provider(Request $request, int $provider): JsonResponse
    {
        $profile = ProviderProfile::publiclyListed()->findOrFail($provider);

        return response()->json(['data' => $this->cards(collect([$this->findPerson($profile->owner_id)]), $request->user('sanctum'))[0]]);
    }

    /** The signed-in member's own card, whether or not they've posted */
    public function me(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $me = $this->people->withCard(User::query())->findOrFail($viewer->getKey());

        return response()->json(['data' => $this->people->card($me, $viewer, [], $this->people->isDiscoverable($me))]);
    }

    public function followers(Request $request, int $user): JsonResponse
    {
        return $this->connections($request, $this->findPerson($user), 'followers');
    }

    public function following(Request $request, int $user): JsonResponse
    {
        return $this->connections($request, $this->findPerson($user), 'following');
    }

    public function follow(Request $request, int $user): JsonResponse
    {
        $on = $request->isMethod('post');
        // Anyone can be unfollowed; only people in the community can be followed
        $person = $on && $user !== $request->user()->getKey() ? $this->findPerson($user) : User::findOrFail($user);
        $count = $this->people->follow($request->user(), $person, $on);

        return response()->json(['data' => ['following' => $on, 'followers_count' => $count]]);
    }

    public function topics(): JsonResponse
    {
        return response()->json(['data' => $this->people->activeTopics()]);
    }

    /* ---------------- Helpers ---------------- */

    private function connections(Request $request, User $person, string $relation): JsonResponse
    {
        $viewer = $request->user('sanctum');
        $page = $this->people->withCard($person->{$relation}()->getQuery())
            ->where('users.status', User::STATUS_ACTIVE)
            ->orderByDesc('follows.created_at')
            ->simplePaginate(self::PER_PAGE);
        $items = collect($page->items());
        // Followers who never posted are listed without a link to a page of their own
        $linkable = $this->people->discoverable()->whereIn('users.id', $items->pluck('id')->all() ?: [0])->pluck('users.id')->flip();

        return response()->json([
            'data' => $this->cards($items, $viewer, $linkable),
            'meta' => ['next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null],
        ]);
    }

    private function cards($people, ?User $viewer, $linkable = null): array
    {
        $followed = $this->people->followedBy($viewer, $people->pluck('id')->all());

        return $people->map(function (User $u) use ($viewer, $followed, $linkable) {
            return $this->people->card($u, $viewer, $followed, $linkable === null || isset($linkable[$u->id]));
        })->values()->all();
    }

    private function findPerson(int $id): User
    {
        $person = $this->people->withCard($this->people->discoverable())->find($id);
        abort_unless($person, 404);

        return $person;
    }
}
