<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestPhoto;
use App\Services\Marketplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class JobController extends Controller
{
    /** Photos of the job: up to 6 JPG, PNG or WebP photos, 8 MB each, re-drawn by the server */
    private const PHOTO_RULES = [
        'photos' => ['nullable', 'array', 'max:'.ServiceRequestPhoto::MAX],
        'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        // Sent by the app with each new request so a retry can't create a copy
        'client_token' => ['nullable', 'uuid'],
    ];

    /**
     * When and where (all optional, so older apps keep working):
     * "as soon as possible" or a start in Uganda time ("2026-11-20 10:00"),
     * the expected length, and the exact point the customer confirmed.
     */
    private const BOOKING_RULES = [
        'timing' => ['nullable', 'in:asap,scheduled'],
        'scheduled_start' => ['nullable', 'required_if:timing,scheduled', 'date_format:Y-m-d H:i'],
        'duration_minutes' => ['nullable', 'integer', 'in:30,60,90,120,180,240,300,360,480,600,720'],
        'latitude' => ['nullable', 'numeric', 'between:-2,5', 'required_with:longitude'],
        'longitude' => ['nullable', 'numeric', 'between:29,36', 'required_with:latitude'],
        'location_accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        'landmark' => ['nullable', 'string', 'max:150'],
        'directions' => ['nullable', 'string', 'max:300'],
    ];

    private const BOOKING_MESSAGES = [
        'scheduled_start.required_if' => 'Choose a date and time, or “As soon as possible”.',
        'scheduled_start.date_format' => 'Choose a date and time.',
        'duration_minutes.in' => 'Choose how long it will take from the list.',
        'latitude.between' => 'That location is outside Uganda. Choose the place on WorkMate instead.',
        'longitude.between' => 'That location is outside Uganda. Choose the place on WorkMate instead.',
        'landmark.max' => 'Keep the landmark under 150 characters.',
        'directions.max' => 'Keep the directions under 300 characters.',
    ];

    private const PHOTO_MESSAGES = [
        'photos.max' => 'Add up to 6 photos.',
        'photos.*.mimes' => 'Use JPG, PNG or WebP photos.',
        'photos.*.max' => 'Each photo can be up to 8 MB.',
        'photos.*.file' => 'One of the photos didn’t upload. Try again.',
    ];

    /** @var Marketplace */
    private $marketplace;

    public function __construct(Marketplace $marketplace)
    {
        $this->marketplace = $marketplace;
    }

    /** Open jobs anyone can apply to, newest first */
    public function index()
    {
        $jobs = ServiceRequest::openJobs()
            ->with(['category', 'area', 'photos'])
            ->withCount('quotations')
            ->latest()
            ->limit(200)
            ->get();

        return JobResource::collection($jobs);
    }

    /** An open job, or any request the signed-in customer made */
    public function show(Request $request, int $id): JobResource
    {
        $job = ServiceRequest::with(['category', 'area', 'provider', 'photos'])->withCount('quotations')->findOrFail($id);
        $user = $request->user('sanctum');

        $visible = ($job->isPostedJob() && $job->status === ServiceRequest::STATUS_OPEN)
            || ($user && $job->customer_id === (int) $user->getKey());
        abort_unless($visible, 404);

        return new JobResource($job);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'exists:categories,slug'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'area' => ['required', 'string', 'exists:areas,slug'],
            'preferred_time' => ['nullable', 'string', 'max:100'],
            'workers_needed' => ['required', 'integer', 'min:1', 'max:50'],
            'budget' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
            'budget_basis' => ['nullable', 'in:fixed,total,per person,per day,per hour,negotiable'],
        ] + self::BOOKING_RULES + self::PHOTO_RULES, [
            'budget.min' => 'Enter a budget of at least UGX 1,000, or leave it empty.',
        ] + self::BOOKING_MESSAGES + self::PHOTO_MESSAGES);

        $job = $this->marketplace->postJob($request->user(), $data, $request->file('photos', []));

        // 200 when this was a retry of a job already posted
        return (new JobResource($job->load('category', 'area', 'photos')))->response()->setStatusCode($job->wasRecentlyCreated ? 201 : 200);
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        $job = ServiceRequest::findOrFail($id);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'start_time' => ['required', 'string', 'max:60'],
            'amount' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
        ]);

        $quotation = $this->marketplace->apply($request->user(), $job, $data);

        return response()->json(['data' => ['id' => $quotation->id, 'status' => $quotation->status]], 201);
    }

    public function requestProvider(Request $request, int $providerId): JsonResponse
    {
        $provider = ProviderProfile::publiclyListed()->findOrFail($providerId);

        $data = $request->validate([
            'category' => ['required', 'string'],
            'description' => ['required', 'string', 'min:15', 'max:2000'],
            'area' => ['required', 'string', 'exists:areas,slug'],
            // Older apps send a description of when; newer ones send timing
            'preferred_time' => ['nullable', 'required_without:timing', 'string', 'max:100'],
            'budget' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
            // One of this provider's listed services, if the customer chose one
            'service' => ['nullable', 'integer'],
        ] + self::BOOKING_RULES + self::PHOTO_RULES, self::BOOKING_MESSAGES + self::PHOTO_MESSAGES);

        $serviceRequest = $this->marketplace->requestProvider($request->user(), $provider, $data, $request->file('photos', []));

        return (new JobResource($serviceRequest->load('category', 'area', 'provider', 'photos')))->response()->setStatusCode($serviceRequest->wasRecentlyCreated ? 201 : 200);
    }

    /** A photo of the job, only for the people involved (ServiceRequest::photosVisibleTo) */
    public function photo(Request $request, int $id, string $file)
    {
        abort_unless(preg_match('/^([0-9a-f-]{36})(-t)?\.jpg$/', $file, $m), 404);
        $job = ServiceRequest::withTrashed()->find($id);
        $photo = $job ? $job->photos()->where('uuid', $m[1])->first() : null;
        abort_unless($photo && $job->photosVisibleTo($request->user('sanctum')), 404);

        $path = empty($m[2]) ? $photo->path : $photo->thumb_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // Someone's home: never stored, not even by the browser (a shared phone keeps no copy)
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
