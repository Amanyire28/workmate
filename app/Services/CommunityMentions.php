<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\CommunityPostResource;
use App\Models\CommunityComment;
use App\Models\CommunityMention;
use App\Models\CommunityPost;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Support\AfterCommit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * @mentions in community posts, captions, comments and replies.
 *
 * How a mention travels: the app inserts a person chosen from the suggestions
 * as a token, @[u:<account id>], and sends the text with its tokens. Here each
 * token is checked (a real account that can be mentioned, at most five people
 * per post or comment) and replaced with that person's community name, e.g.
 * "@Okello Plumbing" or "@Aisha N."; where each name sits in the stored text is
 * kept in community_mentions, by account id. So the stored text stays plain
 * (as moderators, notifications and sharing see it), a typed "@Sarah" that
 * wasn't chosen is only text, and no one can be mentioned without being in
 * the text. Positions are in UTF-16 code units, as browsers and phones count.
 *
 * Who can be mentioned: any active WorkMate account, found by the name the
 * community shows (a provider's business name, or "Aisha N."). Account
 * blocking, when it arrives, belongs in mentionable() and in deliver().
 *
 * People are told only once the post or comment is really saved: the notice
 * is queued with AfterCommit inside the transaction that saves it, so it waits
 * for the outermost transaction and is dropped if anything rolls back.
 */
class CommunityMentions
{
    public const MAX_PER_ITEM = 5;

    public const TYPE_POST = 'post';
    public const TYPE_COMMENT = 'comment';

    /** What the app writes for a chosen person */
    public const TOKEN = '/@\[u:(\d{1,10})\]/';

    // Suggestions per search, and candidates looked at to find them
    private const RESULTS = 8;
    private const CANDIDATES = 40;

    // Being told about mentions: the same person by the same author, and any one author's total
    private const ALERTS_SAME_PERSON_PER_HOUR = 3;
    private const ALERTS_PER_AUTHOR_PER_HOUR = 30;

    /** @var EventNotifications */
    private $notify;

    /** @var AfterCommit */
    private $afterCommit;

    public function __construct(EventNotifications $notify, AfterCommit $afterCommit)
    {
        $this->notify = $notify;
        $this->afterCommit = $afterCommit;
    }

    /* ---------------- Who can be mentioned ---------------- */

    /**
     * Active accounts (deleted ones are left out by the model). Suspended
     * accounts can't be mentioned. (Blocking, when it exists, also goes here:
     * people who blocked $by, or whom $by blocked.)
     */
    public function mentionable(?User $by = null): Builder
    {
        return User::query()->where('users.status', User::STATUS_ACTIVE);
    }

    /**
     * People to suggest for "@…": matched on the name the community shows
     * (a provider's business name, or "Aisha N."), so a member's surname
     * can't be used to find them. People the author follows come first.
     */
    public function suggestions(User $viewer, string $query): Collection
    {
        $wanted = $this->normalise($query);
        if ($wanted === '') {
            return collect();
        }
        $first = explode(' ', $wanted)[0];
        $like = '%'.addcslashes($first, '%_\\').'%';

        $followed = DB::table('follows')->where('follower_id', $viewer->getKey())->pluck('followed_id')->flip();
        $candidates = $this->mentionable($viewer)->whereKeyNot($viewer->getKey())
            ->where(function ($q) use ($like) {
                $q->where('users.name', 'like', $like)->orWhereHas('providerProfile', function ($p) use ($like) {
                    $p->where('status', ProviderProfile::STATUS_APPROVED)->where('display_name', 'like', $like);
                });
            })
            ->with(['providerProfile.owner', 'providerProfile.professions', 'providerProfile.baseArea'])
            // Among many matches, people they follow and listed providers are looked at first
            ->orderByRaw('exists (select 1 from follows where follows.follower_id = ? and follows.followed_id = users.id) desc', [$viewer->getKey()])
            ->orderByRaw('exists (select 1 from provider_profiles where provider_profiles.owner_id = users.id and provider_profiles.status = ?) desc', [ProviderProfile::STATUS_APPROVED])
            ->orderByDesc('users.id')->limit(self::CANDIDATES)->get();

        return $candidates->map(function (User $user) use ($followed) {
            $person = CommunityPostResource::author($user, $user->providerProfile);
            $profile = $user->providerProfile;

            // Telling apart people with the same name: a provider's area (public on their profile), and who they follow
            return $person + [
                'area' => $person['provider_id'] && $profile->baseArea ? $profile->baseArea->name : null,
                'following' => isset($followed[$user->id]),
            ];
        })->filter(function (array $person) use ($wanted) {
            return strpos($this->normalise($person['name']), $wanted) !== false;
        })->sortBy(function (array $person) use ($wanted, $followed) {
            // Followed first, then names starting with what was typed, then alphabetical
            $name = $this->normalise($person['name']);

            return (isset($followed[$person['id']]) ? '0' : '1').(strpos($name, $wanted) === 0 ? '0' : '1').$name;
        })->take(self::RESULTS)->values();
    }

    /* ---------------- Reading the text ---------------- */

    /**
     * Turns the app's tokens into names, checking every person.
     *
     * @return array{0: ?string, 1: array<int, array{user_id: int, start: int, length: int}>}
     *         the text to store, and where each mention sits in it
     *
     * @throws BusinessRuleException
     */
    public function expand(?string $text, User $author, int $maxLength): array
    {
        if ($text === null || ! preg_match_all(self::TOKEN, $text, $found, PREG_OFFSET_CAPTURE)) {
            return [$text, []];
        }

        $ids = array_values(array_unique(array_map(function ($m) {
            return (int) $m[0];
        }, $found[1])));
        if (count($ids) > self::MAX_PER_ITEM) {
            throw new BusinessRuleException('too_many_mentions', 'You can mention up to '.self::MAX_PER_ITEM.' people in one post or comment.', 422);
        }

        // Mentioning yourself is allowed (you're just not told about it)
        $others = array_values(array_diff($ids, [$author->getKey()]));
        $people = $others
            ? $this->mentionable($author)->whereIn('users.id', $others)->with(['providerProfile.owner', 'providerProfile.professions'])->get()->keyBy('id')
            : collect();
        if ($people->count() !== count($others)) {
            throw new BusinessRuleException('mention_not_allowed', 'One of the people you mentioned can’t be mentioned. Remove that mention and try again.', 422);
        }
        if (in_array($author->getKey(), $ids, true)) {
            $people[$author->getKey()] = $author;
        }

        $out = '';
        $at = 0;        // where we are in $out, in UTF-16 code units
        $last = 0;      // where we are in $text, in bytes
        $mentions = [];
        foreach ($found[0] as $i => [$token, $byte]) {
            $before = substr($text, $last, $byte - $last);
            $out .= $before;
            $at += self::utf16Length($before);

            $person = $people[(int) $found[1][$i][0]];
            $name = '@'.CommunityPostResource::author($person, $person->providerProfile)['name'];
            $length = self::utf16Length($name);
            $mentions[] = ['user_id' => $person->getKey(), 'start' => $at, 'length' => $length];
            $out .= $name;
            $at += $length;
            $last = $byte + strlen($token);
        }
        $out .= substr($text, $last);

        if (mb_strlen($out) > $maxLength) {
            throw new BusinessRuleException('text_too_long', 'With the names filled in, this is longer than '.$maxLength.' characters. Shorten it a little.', 422);
        }

        return [$out, $mentions];
    }

    /** Checks the text without storing anything (a video post is checked before its upload) */
    public function check(?string $text, User $author, int $maxLength): void
    {
        $this->expand($text, $author, $maxLength);
    }

    /* ---------------- Storing and telling people ---------------- */

    /**
     * Stores the mentions for a post or comment (replacing any before) and
     * claims who should be told: people not told about this item before,
     * apart from the author and $skip (already told by another notice).
     * Call inside the transaction that saves the text: they're told once the
     * outermost transaction commits, and not at all if it rolls back (the
     * claims roll back with it, so a retry still tells them).
     *
     * @param  array<int, array{user_id: int, start: int, length: int}>  $mentions
     * @param  int[]  $skip
     * @return int[] the people who will be told
     */
    public function record(string $type, int $id, int $postId, User $author, array $mentions, array $skip = []): ?array
    {
        $this->guardType($type);
        CommunityMention::where('mentionable_type', $type)->where('mentionable_id', $id)->delete();
        $now = now();
        if ($mentions) {
            CommunityMention::insert(array_map(function ($m) use ($type, $id, $now) {
                return $m + ['mentionable_type' => $type, 'mentionable_id' => $id, 'created_at' => $now, 'updated_at' => $now];
            }, $mentions));
        }

        $new = [];
        $people = collect($mentions)->pluck('user_id')->unique()->reject(function ($userId) use ($author, $skip) {
            return $userId === $author->getKey() || in_array($userId, $skip, true);
        });
        foreach ($people as $userId) {
            // Once per post or comment, ever: editing, or removing and adding again, doesn't repeat it
            $claimed = DB::table('community_mention_alerts')->insertOrIgnore([
                'mentionable_type' => $type, 'mentionable_id' => $id, 'user_id' => $userId, 'created_at' => $now,
            ]);
            if ($claimed) {
                $new[] = $userId;
            }
        }

        if ($new) {
            $pending = ['type' => $type, 'id' => $id, 'post_id' => $postId, 'author_id' => $author->getKey(), 'user_ids' => $new];
            $this->afterCommit->run(function () use ($pending) {
                $this->deliver($pending);
            });
        }

        return $new;
    }

    /**
     * Tells the people record() claimed (run by AfterCommit once saved). Nothing is sent for content that is no longer visible, and an
     * author mentioning the same person (or many people) over and over is
     * quietly limited: the mention still shows, without another notification.
     */
    public function deliver(?array $pending): void
    {
        if (! $pending) {
            return;
        }
        $post = CommunityPost::find($pending['post_id']);
        $author = User::find($pending['author_id']);
        if (! $post || ! $post->isVisible() || ! $author || ! $author->isActive()) {
            return;
        }
        $comment = null;
        if ($pending['type'] === self::TYPE_COMMENT) {
            // A comment is told about only while its post can be seen too
            $comment = CommunityComment::find($pending['id']);
            if (! $comment || ! $comment->isVisible()) {
                return;
            }
        }

        $recipients = [];
        foreach ($pending['user_ids'] as $userId) {
            $pair = 'mention-alert:'.$author->getKey().':'.$userId;
            $total = 'mention-alerts:'.$author->getKey();
            if (RateLimiter::tooManyAttempts($pair, self::ALERTS_SAME_PERSON_PER_HOUR)
                || RateLimiter::tooManyAttempts($total, self::ALERTS_PER_AUTHOR_PER_HOUR)) {
                continue;
            }
            RateLimiter::hit($pair, 3600);
            RateLimiter::hit($total, 3600);
            $recipients[] = $userId;
        }
        if ($recipients) {
            // Still someone who can be mentioned now (e.g. not suspended meanwhile)
            $recipients = $this->mentionable($author)->whereIn('users.id', $recipients)->pluck('users.id')->all();
            $this->notify->mentioned($post, $comment, $author, $recipients);
        }
    }

    /** Removes the mentions of something deleted for good */
    public function forget(string $type, int $id): void
    {
        $this->guardType($type);
        CommunityMention::where('mentionable_type', $type)->where('mentionable_id', $id)->delete();
        DB::table('community_mention_alerts')->where('mentionable_type', $type)->where('mentionable_id', $id)->delete();
    }

    /* ---------------- Showing them ---------------- */

    /**
     * Mentions as the app needs them to draw links: where they are, and where
     * they lead. Listed providers open their provider profile; everyone else
     * their community page, which anyone mentioned in visible content has
     * (CommunityPeople::findable). Someone no longer active, and mentions in a
     * post WorkMate removed (shown only to its author), stay as plain text.
     *
     * @param  Collection<int, CommunityMention>  $mentions  loaded with user (CommunityPost::mentions())
     */
    public static function present(Collection $mentions, bool $links = true): array
    {
        return $mentions->sortBy('start')->map(function (CommunityMention $m) use ($links) {
            $user = $m->user;
            $active = $links && $user && ! $user->trashed() && $user->isActive();
            $profile = $active ? $user->providerProfile : null;
            $listed = $profile && $profile->isApproved() && $profile->owner_id === $user->id;

            return [
                'start' => $m->start,
                'length' => $m->length,
                'user_id' => $m->user_id,
                'provider_id' => $listed ? $profile->id : null,
                'linkable' => $active,
            ];
        })->values()->all();
    }

    /* ---------------- Helpers ---------------- */

    /** Length as browsers count it (UTF-16 code units) */
    public static function utf16Length(string $text): int
    {
        return $text === '' ? 0 : intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** Lower case, no dots, single spaces: "Aisha N." and "aisha n" match */
    private function normalise(string $text): string
    {
        $text = mb_strtolower(str_replace('.', '', $text));

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function guardType(string $type): void
    {
        if (! in_array($type, [self::TYPE_POST, self::TYPE_COMMENT], true)) {
            throw new \InvalidArgumentException('Unknown mention type: '.$type);
        }
    }
}
