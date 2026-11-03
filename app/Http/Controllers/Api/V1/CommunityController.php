<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunityCommentResource;
use App\Http\Resources\CommunityPostResource;
use App\Models\Area;
use App\Models\Category;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\User;
use App\Services\Community;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** The community feed: anyone reads; members with a confirmed email take part */
class CommunityController extends Controller
{
    private const PER_PAGE = 10;
    private const COMMENTS_PER_PAGE = 20;

    /** @var Community */
    private $community;

    public function __construct(Community $community)
    {
        $this->community = $community;
    }

    /* ---------------- Reading ---------------- */

    /**
     * Newest first. "For you" narrows to the viewer's professions and area when
     * WorkMate knows them; filters narrow by category, profession and area.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['for_you', 'latest'])],
            'category' => ['nullable', Rule::in(array_keys(CommunityPost::CATEGORIES))],
            'topic' => ['nullable', 'string', 'max:50'],
            'area' => ['nullable', 'string', 'max:60'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);
        $viewer = $request->user('sanctum');

        $query = CommunityPost::visible()->with(CommunityPostResource::RELATIONS);
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['topic'])) {
            $query->where('topic_category_id', Category::where('slug', $filters['topic'])->value('id') ?: 0);
        }
        if (! empty($filters['area'])) {
            $query->where('area_id', Area::where('slug', $filters['area'])->value('id') ?: 0);
        }

        $personal = ($filters['tab'] ?? 'for_you') === 'for_you' ? $this->interests($viewer) : null;
        if ($personal) {
            $query->where(function ($q) use ($personal) {
                $q->whereIn('topic_category_id', $personal['topics'] ?: [0]);
                if ($personal['area']) {
                    $q->orWhere('area_id', $personal['area']);
                }
            });
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => CommunityPostResource::collection($this->withViewerFlags(collect($page->items()), $viewer)),
            'meta' => ['next_cursor' => optional($page->nextCursor())->encode(), 'personalised' => (bool) $personal],
        ]);
    }

    public function show(Request $request, string $post): JsonResponse
    {
        $post = $this->findReadable($request, $post);
        $post->load(CommunityPostResource::RELATIONS);
        $this->withViewerFlags(collect([$post]), $request->user('sanctum'));

        return response()->json(['data' => new CommunityPostResource($post)]);
    }

    /** The signed-in member's saved posts, most recently saved first */
    public function saved(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $page = CommunityPost::visible()->with(CommunityPostResource::RELATIONS)
            ->join('community_post_saves as s', 's.community_post_id', '=', 'community_posts.id')
            ->where('s.user_id', $viewer->getKey())
            ->orderByDesc('s.created_at')->orderByDesc('s.id')
            ->select('community_posts.*')
            ->simplePaginate(self::PER_PAGE);

        return response()->json([
            'data' => CommunityPostResource::collection($this->withViewerFlags(collect($page->items()), $viewer)),
            'meta' => ['next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null],
        ]);
    }

    /** Top-level comments, oldest first, each with its replies */
    public function comments(Request $request, string $post): JsonResponse
    {
        $post = $this->findReadable($request, $post);
        $authorRelations = ['author.providerProfile.owner', 'author.providerProfile.professions'];

        $page = CommunityComment::withTrashed()
            ->where('community_post_id', $post->id)->whereNull('parent_id')
            // Deleted comments keep their place only while they have replies
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->whereNull('deleted_at')->where('status', CommunityComment::STATUS_PUBLISHED);
                })->orWhereHas('replies', function ($r) {
                    $r->where('status', CommunityComment::STATUS_PUBLISHED);
                });
            })
            ->with(array_merge($authorRelations, ['replies' => function ($r) {
                $r->where('status', CommunityComment::STATUS_PUBLISHED)->orderBy('created_at')->orderBy('id');
            }], array_map(function ($rel) {
                return 'replies.'.$rel;
            }, $authorRelations)))
            ->orderBy('created_at')->orderBy('id')
            ->cursorPaginate(self::COMMENTS_PER_PAGE);

        $items = collect($page->items())->each(function (CommunityComment $c) use ($post) {
            $c->setRelation('post', $post);
            $c->replies->each(function ($r) use ($post) {
                $r->setRelation('post', $post);
            });
        });

        return response()->json([
            'data' => CommunityCommentResource::collection($items),
            'meta' => ['next_cursor' => optional($page->nextCursor())->encode(), 'total' => $post->comments_count],
        ]);
    }

    /** A post's photo: served only while the post can be seen */
    public function media(Request $request, string $file)
    {
        abort_unless(preg_match('/^([0-9a-f-]{36})(-t)?\.jpg$/', $file, $m), 404);
        $media = CommunityPostMedia::where('uuid', $m[1])->first();
        abort_unless($media, 404);

        $post = $media->post;
        $public = $post && $post->isVisible();
        $viewer = $request->user('sanctum');
        $author = $post && ! $post->trashed() && $viewer && $viewer->getKey() === $post->user_id;
        // The safety team looks at reported photos even after removal
        $moderator = $post && $viewer && ($viewer->can('trust.view') || $viewer->can('content.view'));
        abort_unless($public || $author || $moderator, 404);

        $path = empty($m[2]) ? $media->path : $media->thumb_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // Kept short, so a removed post's photos stop showing soon
        $public ? $response->setPublic()->setMaxAge(3600) : $response->setPrivate();

        return $response;
    }

    /* ---------------- Taking part ---------------- */

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(CommunityPost::CATEGORIES))],
            'body' => ['nullable', 'string', 'max:'.CommunityPost::MAX_BODY],
            'topic' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'photos' => ['nullable', 'array', 'max:'.CommunityPost::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], $this->messages());

        $post = $this->community->publish($request->user(), $data, $request->file('photos', []));
        $post->load(CommunityPostResource::RELATIONS);

        return response()->json(['data' => new CommunityPostResource($post)], 201);
    }

    public function update(Request $request, string $post): JsonResponse
    {
        $post = $this->findOwn($request, $post);
        $data = $request->validate([
            'category' => ['sometimes', 'required', Rule::in(array_keys(CommunityPost::CATEGORIES))],
            'body' => ['sometimes', 'nullable', 'string', 'max:'.CommunityPost::MAX_BODY],
            'topic' => ['sometimes', 'nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
        ], $this->messages());

        $post = $this->community->edit($request->user(), $post, $data);
        $post->load(CommunityPostResource::RELATIONS);
        $this->withViewerFlags(collect([$post]), $request->user());

        return response()->json(['data' => new CommunityPostResource($post)]);
    }

    public function destroy(Request $request, string $post): JsonResponse
    {
        $this->community->delete($request->user(), $this->findOwn($request, $post));

        return response()->json(['data' => null]);
    }

    public function like(Request $request, string $post): JsonResponse
    {
        $count = $this->community->like($request->user(), $this->findVisible($post), $request->isMethod('post'));

        return response()->json(['data' => ['liked' => $request->isMethod('post'), 'likes_count' => $count]]);
    }

    public function save(Request $request, string $post): JsonResponse
    {
        $on = $request->isMethod('post');
        $found = $on ? $this->findVisible($post) : CommunityPost::withTrashed()->where('uuid', $post)->firstOrFail();
        $this->community->save($request->user(), $found, $on);

        return response()->json(['data' => ['saved' => $on]]);
    }

    public function comment(Request $request, string $post): JsonResponse
    {
        $post = $this->findVisible($post);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.CommunityComment::MAX_BODY],
            'parent' => ['nullable', 'integer'],
        ], ['body.required' => 'Write a comment first.', 'body.max' => 'Comments can be up to '.CommunityComment::MAX_BODY.' characters.']);

        $comment = $this->community->comment($request->user(), $post, $data['body'], $data['parent'] ?? null);
        $comment->load(['author.providerProfile.owner', 'author.providerProfile.professions'])->setRelation('post', $post)->setRelation('replies', collect());

        return response()->json(['data' => new CommunityCommentResource($comment), 'meta' => ['comments_count' => $post->fresh()->comments_count]], 201);
    }

    public function destroyComment(Request $request, int $comment): JsonResponse
    {
        $comment = CommunityComment::with('post')->findOrFail($comment);
        $this->community->deleteComment($request->user(), $comment);

        return response()->json(['data' => null, 'meta' => ['comments_count' => (int) optional($comment->post()->first())->comments_count]]);
    }

    /* ---------------- Helpers ---------------- */

    /** Visible to everyone, or the author's own post (even if WorkMate removed it) */
    private function findReadable(Request $request, string $uuid): CommunityPost
    {
        $post = CommunityPost::where('uuid', $uuid)->first();
        $viewer = $request->user('sanctum');
        abort_unless($post && ($post->isVisible() || ($viewer && $viewer->getKey() === $post->user_id)), 404);

        return $post;
    }

    private function findVisible(string $uuid): CommunityPost
    {
        $post = CommunityPost::where('uuid', $uuid)->first();
        abort_unless($post && $post->isVisible(), 404);

        return $post;
    }

    private function findOwn(Request $request, string $uuid): CommunityPost
    {
        $post = CommunityPost::where('uuid', $uuid)->where('user_id', $request->user()->getKey())->first();
        abort_unless($post, 404);

        return $post;
    }

    /** Whether the viewer liked and saved each post, in two queries for the whole page */
    private function withViewerFlags(Collection $posts, ?User $viewer): Collection
    {
        if (! $viewer || $posts->isEmpty()) {
            return $posts;
        }
        $ids = $posts->pluck('id')->all();
        $liked = DB::table('community_post_likes')->where('user_id', $viewer->getKey())->whereIn('community_post_id', $ids)->pluck('community_post_id')->flip();
        $saved = DB::table('community_post_saves')->where('user_id', $viewer->getKey())->whereIn('community_post_id', $ids)->pluck('community_post_id')->flip();

        return $posts->each(function (CommunityPost $p) use ($liked, $saved) {
            $p->viewer_liked = isset($liked[$p->id]);
            $p->viewer_saved = isset($saved[$p->id]);
        });
    }

    /** The professions and area WorkMate knows the viewer works in, or null */
    private function interests(?User $viewer): ?array
    {
        $profile = $viewer ? $viewer->providerProfile : null;
        if (! $profile) {
            return null;
        }
        $topics = $profile->professions()->pluck('category_id')->unique()->values()->all();
        if (! $topics && ! $profile->base_area_id) {
            return null;
        }

        return ['topics' => $topics, 'area' => $profile->base_area_id];
    }

    private function messages(): array
    {
        return [
            'category.required' => 'Choose what kind of post this is.',
            'body.max' => 'Posts can be up to '.CommunityPost::MAX_BODY.' characters.',
            'photos.max' => 'Add up to '.CommunityPost::MAX_PHOTOS.' photos.',
            'photos.*.mimes' => 'Use JPG, PNG or WebP photos.',
            'photos.*.max' => 'Each photo can be up to 8 MB.',
            'topic.exists' => 'Choose a profession from the list.',
        ];
    }
}
