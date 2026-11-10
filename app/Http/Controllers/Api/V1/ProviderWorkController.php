<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Models\ProviderWorkItem;
use App\Models\ProviderWorkPhoto;
use App\Services\ProviderWork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** "Recent work" on provider profiles */
class ProviderWorkController extends Controller
{
    /** @var ProviderWork */
    private $work;

    public function __construct(ProviderWork $work)
    {
        $this->work = $work;
    }

    /** A provider's published work, for anyone looking at their profile */
    public function index(int $provider): JsonResponse
    {
        $profile = ProviderProfile::publiclyListed()->findOrFail($provider);

        return response()->json(['data' => $this->work->listFor($profile)]);
    }

    /** All of my work, including anything WorkMate removed and why */
    public function mine(Request $request): JsonResponse
    {
        $profile = $request->user()->providerProfile;

        return response()->json(['data' => $profile ? $this->work->listFor($profile, true) : []]);
    }

    public function store(Request $request): JsonResponse
    {
        // 1 to 6 photos for a featured project, 1 to 4 for other work; the provider picks how many
        $featured = $request->boolean('featured');
        $data = $request->validate($this->rules() + [
            'share' => ['nullable', 'boolean'],
            'photos' => ['required', 'array', 'min:1', 'max:'.($featured ? ProviderWorkItem::FEATURED_MAX_PHOTOS : ProviderWorkItem::MAX_PHOTOS)],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], $this->messages($featured));

        $photos = $request->file('photos', []);
        $item = $this->work->add($request->user(), $data, $photos);

        // Sharing to the community is a bonus: the work is saved either way
        $shareError = null;
        if (! empty($data['share'])) {
            try {
                $this->work->shareToCommunity($request->user(), $item, $photos);
            } catch (BusinessRuleException $e) {
                $shareError = $e->getMessage();
            }
        }

        return response()->json(['data' => $this->work->present($item->fresh(['photos', 'category', 'area']), true), 'share_error' => $shareError], 201);
    }

    public function update(Request $request, string $item): JsonResponse
    {
        $data = $request->validate(array_map(function ($rule) {
            return array_merge(['sometimes'], $rule);
        }, $this->rules()), $this->messages());

        $item = $this->work->edit($request->user(), $this->find($item), $data);

        return response()->json(['data' => $this->work->present($item->fresh(['photos', 'category', 'area']), true)]);
    }

    public function destroy(Request $request, string $item): JsonResponse
    {
        $this->work->delete($request->user(), $this->find($item));

        return response()->json(['data' => ['id' => $item, 'deleted' => true]]);
    }

    /** Photos of published work are public; the owner and moderators also see removed ones */
    public function media(Request $request, string $file)
    {
        abort_unless(preg_match('/^([0-9a-f-]{36})(-t)?\.jpg$/', $file, $m), 404);
        $photo = ProviderWorkPhoto::where('uuid', $m[1])->first();
        abort_unless($photo, 404);

        $item = $photo->item;
        $public = $item && $item->isVisible();
        $viewer = $request->user('sanctum');
        $owner = $item && ! $item->trashed() && $viewer && optional($item->provider)->owner_id === $viewer->getKey();
        // The safety team looks at reported photos even after removal
        $moderator = $item && $viewer && ($viewer->can('trust.view') || $viewer->can('content.view'));
        abort_unless($public || $owner || $moderator, 404);

        $path = empty($m[2]) ? $photo->path : $photo->thumb_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // Kept short, so removed work stops showing soon
        $public ? $response->setPublic()->setMaxAge(3600) : $response->setPrivate();

        return $response;
    }

    private function find(string $uuid): ProviderWorkItem
    {
        return ProviderWorkItem::where('uuid', $uuid)->firstOrFail();
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'area' => ['nullable', 'string', Rule::exists('areas', 'slug')->where('is_active', true)],
            'done_on' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'featured' => ['nullable', 'boolean'],
        ];
    }

    private function messages(bool $featured = false): array
    {
        $photos = $featured
            ? [
                'photos.required' => 'Add at least one photo of the project.',
                'photos.min' => 'Add at least one photo of the project.',
                'photos.max' => 'Add up to '.ProviderWorkItem::FEATURED_MAX_PHOTOS.' photos.',
            ]
            : [];

        return $photos + [
            'title.required' => 'Say what the job was.',
            'title.max' => 'Keep the title under 120 characters.',
            'description.max' => 'Keep the description under 500 characters.',
            'done_on.date_format' => 'Choose the month the job was done.',
            'done_on.before_or_equal' => 'Choose a month that has already happened.',
            'photos.required' => 'Add at least one photo of the work.',
            'photos.min' => 'Add at least one photo of the work.',
            'photos.max' => 'Add up to '.ProviderWorkItem::MAX_PHOTOS.' photos.',
            'photos.*.mimes' => 'Use JPG, PNG or WebP photos.',
            'photos.*.max' => 'Each photo can be up to 8 MB.',
        ];
    }
}
