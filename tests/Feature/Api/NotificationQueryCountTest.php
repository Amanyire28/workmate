<?php

namespace Tests\Feature\Api;

use App\Models\CommunityPost;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Community;
use App\Services\NotificationPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The notification list and the "who" sheet read what they show in a fixed number of queries,
 * however many notifications, people or comments there are (no query per row).
 */
class NotificationQueryCountTest extends ApiTestCase
{
    /** @var User[] */
    private $pool = [];

    private function pool(int $n = 12): array
    {
        while (count($this->pool) < $n) {
            $i = count($this->pool);
            $this->pool[] = User::factory()->create(['name' => 'Person Number'.$i]);
        }

        return $this->pool;
    }

    private function newPost(User $author, bool $video = false): CommunityPost
    {
        $p = new CommunityPost();
        $p->forceFill(['uuid' => (string) Str::uuid(), 'user_id' => $author->id, 'category' => 'tips', 'body' => 'x'])->save();
        if ($video) {
            DB::table('community_post_media')->insert(['community_post_id' => $p->id, 'uuid' => (string) Str::uuid(), 'type' => 'video', 'path' => 'x', 'thumb_path' => 'y', 'width' => 1, 'height' => 1, 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $p;
    }

    /** $n notifications of every kind the list knows, each about its own post, from a small crowd of people */
    private function fill(User $author, int $n): void
    {
        $c = app(Community::class);
        $people = $this->pool();
        for ($i = 0; $i < $n; $i++) {
            $post = $this->newPost($author, $i % 2 === 0);
            switch ($i % 4) {
                case 0:   // a like group of three
                    foreach (array_slice($people, 0, 3) as $p) { $c->like($p, $post, true); }
                    break;
                case 1:   // one comment
                    $c->comment($people[$i % 12], $post, 'Comment '.$i);
                    break;
                case 2:   // a comment group: six comments, the same person several times
                    foreach ([0, 1, 1, 2, 2, 2] as $k) { $c->comment($people[$k], $post, 'Burst '.$i); }
                    break;
                default:  // a reply to the author's own comment
                    $mine = $c->comment($author, $post, 'Mine '.$i);
                    $c->comment($people[3], $post, 'Reply '.$i, $mine->id);
            }
        }
    }

    private function queries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $n = count(array_filter(DB::getQueryLog(), function ($q) {
            return strpos($q['query'], 'push_subscriptions') === false;   // (later push jobs of the test app, not the page)
        }));
        DB::disableQueryLog();

        return $n;
    }

    public function test_the_list_costs_the_same_however_many_notifications_there_are()
    {
        $author = User::factory()->create(['name' => 'Grace Akello']);
        $this->signIn($author);
        $counts = [];
        $made = 0;
        foreach ([0, 10, 50, 100] as $target) {
            $this->fill($author, $target - $made);
            $made = $target;
            $this->signIn($author);
            $counts[$target] = $this->queries(function () {
                $this->getJson('/api/v1/me/notifications')->assertOk();
            });
        }

        // 10, 50 and 100 notifications (a page shows 20): the same handful of queries
        $this->assertSame($counts[10], $counts[50]);
        $this->assertSame($counts[50], $counts[100]);
        $this->assertLessThanOrEqual(16, $counts[100]);
        fwrite(STDERR, "\nlist queries by notifications in the account: ".json_encode($counts)."\n");
    }

    public function test_the_presenter_costs_the_same_for_ten_fifty_and_a_hundred_rows_of_every_kind()
    {
        $author = User::factory()->create(['name' => 'Grace Akello']);
        $this->fill($author, 100);
        $all = UserNotification::where('user_id', $author->id)->whereNull('parent_id')->orderByDesc('id')->get();
        $this->assertGreaterThanOrEqual(100, $all->count());
        $presenter = app(NotificationPresenter::class);

        $counts = [];
        foreach ([10, 50, 100] as $n) {
            $rows = $all->take($n);
            $counts[$n] = $this->queries(function () use ($presenter, $rows) {
                $presenter->present($rows);
            });
        }
        $this->assertSame($counts[10], $counts[50]);
        $this->assertSame($counts[50], $counts[100]);
        $this->assertLessThanOrEqual(14, $counts[100]);
        fwrite(STDERR, "\npresenter queries for 10/50/100 rows: ".json_encode($counts)."\n");
    }

    public function test_many_people_or_many_comments_by_one_person_add_no_queries()
    {
        $author = User::factory()->create(['name' => 'Grace Akello']);
        $c = app(Community::class);
        $few = $this->newPost($author, true);
        $c->like($this->pool()[0], $few, true);
        $this->signIn($author);
        $one = $this->queries(function () {
            $this->getJson('/api/v1/me/notifications')->assertOk();
        });

        $crowd = $this->newPost($author, true);
        foreach (range(1, 40) as $i) {
            $c->like(User::factory()->create(['name' => 'Fan Number'.$i]), $crowd, true);
        }
        $same = $this->newPost($author, true);
        foreach (range(1, 40) as $i) {
            $c->comment($this->pool()[0], $same, 'Again '.$i);   // one person, forty comments
        }
        $this->signIn($author);
        $many = $this->queries(function () {
            $this->getJson('/api/v1/me/notifications')->assertOk();
        });
        $this->assertLessThanOrEqual(16, $many);
        fwrite(STDERR, "\nqueries, one like vs 40 likers + 40 comments by one person: $one vs $many\n");
    }

    public function test_the_who_sheet_costs_the_same_for_two_people_or_fifty()
    {
        $author = User::factory()->create(['name' => 'Grace Akello']);
        $c = app(Community::class);
        $small = $this->newPost($author, true);
        foreach (array_slice($this->pool(), 0, 2) as $p) { $c->like($p, $small, true); }
        $big = $this->newPost($author, true);
        foreach (range(1, 50) as $i) {
            $c->like(User::factory()->create(['name' => 'Fan Number'.$i]), $big, true);
        }
        $burst = $this->newPost($author, true);
        foreach (range(1, 50) as $i) {
            $c->comment($this->pool()[$i % 12], $burst, 'Comment '.$i);
        }
        $this->signIn($author);
        $rows = $this->getJson('/api/v1/me/notifications')->assertOk()->json('data');
        $byCount = collect($rows)->where('kind', 'community_likes')->sortBy('count')->values();
        $smallId = $byCount[0]['id'];
        $bigId = $byCount[1]['id'];
        $burstId = collect($rows)->whereNotNull('comments')->first()['id'];

        $a = $this->queries(function () use ($smallId) { $this->getJson("/api/v1/me/notifications/{$smallId}/activity")->assertOk(); });
        $b = $this->queries(function () use ($bigId) { $this->getJson("/api/v1/me/notifications/{$bigId}/activity")->assertOk(); });
        $cq = $this->queries(function () use ($burstId) { $this->getJson("/api/v1/me/notifications/{$burstId}/activity")->assertOk(); });
        $this->assertSame($a, $b);
        $this->assertLessThanOrEqual(10, $cq);
        $this->assertCount(50, $this->getJson("/api/v1/me/notifications/{$bigId}/activity")->json('data.actors'));
        fwrite(STDERR, "\nactivity queries: 2 people $a, 50 people $b, 50 comments $cq\n");
    }
}
