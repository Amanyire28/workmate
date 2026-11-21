<?php

namespace App\Services;

use App\Models\CommunityComment;
use App\Models\UserNotification;
use Illuminate\Support\Collection;

/**
 * Turns notification rows into what the notification list shows, with the data read as it is NOW and
 * a fixed number of queries for a whole page:
 *  - who did it (real name and profile picture, a small copy), up to three for a group, newest first,
 *    and where their profile is (only if they have a page anyone may open);
 *  - what it is about, as a person would say it: video, photo, question or post;
 *  - for a comment, a short plain-text preview, only while the comment can still be seen;
 *  - counts that are true now (people who still like it, comments that are still there).
 * The words stored on the row (written when it was made) are only used for notifications that are not
 * about people (bookings, messages, account notices) and as a fallback.
 */
class NotificationPresenter
{
    /** @var EngagementNotifications */
    private $engagement;

    /** @var CommentNotifications */
    private $comments;

    /** @var CommunityPeople */
    private $people;

    public function __construct(EngagementNotifications $engagement, CommentNotifications $comments, CommunityPeople $people)
    {
        $this->engagement = $engagement;
        $this->comments = $comments;
        $this->people = $people;
    }

    /**
     * @param  Collection<int, UserNotification>  $items
     * @return array<int, array>  the rows to show, in order (ones that have nothing left to show are left out)
     */
    public function present(Collection $items): array
    {
        $likesAndFollows = $this->engagement->peopleFor($items);
        $bursts = $this->comments->stateOf($items->where('subject_type', 'burst')->pluck('id')->all());
        $commentRows = $items->whereIn('subject_type', ['comment', 'reply']);
        $previews = $this->comments->previewsFor($commentRows->pluck('subject_id'));
        $commentPosts = $commentRows->isEmpty() ? [] : CommunityComment::whereIn('id', $commentRows->pluck('subject_id')->all())->pluck('community_post_id', 'id')->all();

        $postIds = array_merge(
            $items->where('kind', EngagementNotifications::LIKES)->whereNotNull('group_key')->pluck('subject_id')->all(),
            $items->where('subject_type', 'burst')->pluck('subject_id')->all(),
            array_values($commentPosts)
        );
        $labels = $this->engagement->contentLabels($postIds);

        // Everyone who will be shown: groups bring their own people; single notifications name one
        $singleIds = $items->filter(function ($n) {
            return $n->group_key === null && $n->actor_id;
        })->pluck('actor_id')->all();
        $singles = $this->engagement->users($singleIds);
        $inGroups = collect($likesAndFollows)->pluck('actors')->concat(collect($bursts)->pluck('actors'))->flatten(1)->pluck('id')->all();
        $pages = $this->pageOwners(array_merge($singleIds, $inGroups));

        $rows = [];
        foreach ($items as $n) {
            $row = $this->row($n, $likesAndFollows, $bursts, $singles, $previews, $commentPosts, $labels, $pages);
            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** One row, or null if there is nothing left of it to show */
    private function row(UserNotification $n, array $groups, array $bursts, array $singles, array $previews, array $commentPosts, array $labels, array $pages): ?array
    {
        $base = [
            'id' => $n->id, 'kind' => $n->kind, 'priority' => UserNotifications::priority($n->kind), 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
            'lead' => null, 'tail' => null, 'detail' => null, 'preview' => null, 'content' => null,
            'grouped' => false, 'count' => null, 'comments' => null, 'activity' => false, 'actors' => [],
            'read' => $n->read_at !== null, 'at' => $n->created_at->toIso8601String(),
        ];

        // Likes and new followers: one notification for the group
        if ($n->group_key !== null && in_array($n->kind, [EngagementNotifications::LIKES, EngagementNotifications::FOLLOWS], true)) {
            $g = $groups[$n->id] ?? ['count' => 0, 'actors' => []];
            if ($g['count'] < 1) {
                return null;                                              // everyone in it has since gone
            }
            $likes = $n->kind === EngagementNotifications::LIKES;
            $label = $likes ? ($labels[$n->subject_id] ?? 'post') : null;
            [$lead, $tail] = EngagementNotifications::phrase($likes ? 'like' : 'follow', array_column($g['actors'], 'name'), $g['count'], ['label' => $label ?: 'post']);
            [$title] = $this->engagement->compose($n->kind, $label ?: 'post', array_column($g['actors'], 'name'), $g['count']);

            return array_merge($base, [
                'title' => $title, 'body' => $lead.$tail, 'lead' => $lead, 'tail' => $tail, 'content' => $label, 'grouped' => true, 'count' => $g['count'],
                'activity' => $g['count'] > 1, 'actors' => $this->actors($g['actors'], $pages),
                'url' => ! $likes && $g['actors'] ? '/community/people/'.$g['actors'][0]['id'] : $n->url,
            ]);
        }

        // Comments that were grouped
        if ($n->subject_type === 'burst') {
            $b = $bursts[$n->id] ?? ['total' => 0, 'distinct' => 0, 'actors' => []];
            if ($b['total'] < 1) {
                return null;
            }
            $label = $labels[$n->subject_id] ?? 'post';
            [$lead, $tail] = EngagementNotifications::phrase('burst', array_column($b['actors'], 'name'), $b['distinct'], ['label' => $label, 'total' => $b['total']]);

            return array_merge($base, [
                'title' => 'New comments', 'body' => $lead.$tail, 'lead' => $lead, 'tail' => $tail, 'detail' => $b['total'].' new '.($b['total'] === 1 ? 'comment' : 'comments'),
                'content' => $label, 'grouped' => true, 'count' => $b['distinct'], 'comments' => $b['total'], 'activity' => true, 'actors' => $this->actors($b['actors'], $pages),
            ]);
        }

        // One comment, or one reply
        if (in_array($n->subject_type, ['comment', 'reply'], true)) {
            $actor = $singles[$n->actor_id] ?? null;
            if (! $actor) {
                return null;
            }
            $label = $labels[$commentPosts[$n->subject_id] ?? 0] ?? 'post';
            [$lead, $tail] = EngagementNotifications::phrase($n->subject_type === 'reply' ? 'reply' : 'comment', [$actor['name']], 1, ['label' => $label]);

            return array_merge($base, [
                'body' => $lead.$tail, 'lead' => $lead, 'tail' => $tail, 'preview' => $previews[$n->subject_id] ?? null,
                'content' => $label, 'actors' => $this->actors([$actor], $pages),
            ]);
        }

        // Everything else (a mention, a booking, a message, an account notice): its own words, with the person where there is one
        $actor = $singles[$n->actor_id] ?? null;
        if ($actor) {
            $base['actors'] = $this->actors([$actor], $pages);
            if (strpos($n->body, $actor['name']) === 0) {
                $base['lead'] = $actor['name'];
                $base['tail'] = substr($n->body, strlen($actor['name']));
            }
        }

        return $base;
    }

    /** The people as the list needs them: who, their small picture, and where their page is (null if they have none to open) */
    private function actors(array $people, array $pages): array
    {
        return array_values(array_map(function ($a) use ($pages) {
            return [
                'id' => $a['id'], 'name' => $a['name'], 'avatar_url' => $a['avatar_url'] ?? null,
                'profile' => isset($pages[$a['id']]) ? ($a['provider_id'] ? '/provider/'.$a['provider_id'] : '/community/people/'.$a['id']) : null,
            ];
        }, $people));
    }

    /** Which of these people have a page anyone may open (a listed provider, or someone who has posted), in one query */
    private function pageOwners(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (! $ids) {
            return [];
        }

        return $this->people->findable()->whereIn('users.id', $ids)->pluck('users.id')->flip()->all();
    }

    /**
     * Everyone in a group, or every comment in a comment group, for the "who" sheet (up to 50, newest first).
     * The caller has already checked the notification is the signed-in person's own and can be seen.
     */
    public function activity(UserNotification $n): array
    {
        if ($n->group_key !== null && in_array($n->kind, [EngagementNotifications::LIKES, EngagementNotifications::FOLLOWS], true)) {
            $people = $this->engagement->actorsOf($n);

            return ['actors' => $this->actors($people, $this->pageOwners(array_column($people, 'id'))), 'comments' => []];
        }
        if ($n->subject_type === 'burst') {
            $comments = $this->comments->commentsOf($n);
            $pages = $this->pageOwners(array_filter(array_map(function ($c) {
                return $c['actor']['id'] ?? null;
            }, $comments)));

            return ['actors' => [], 'comments' => array_map(function ($c) use ($pages) {
                return ['id' => $c['id'], 'preview' => $c['preview'], 'url' => $c['url'], 'at' => $c['at'], 'actor' => $this->actors([$c['actor']], $pages)[0]];
            }, $comments)];
        }

        return ['actors' => [], 'comments' => []];
    }
}
