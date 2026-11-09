<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccountWarning;
use App\Models\Report;
use App\Services\PrivateFiles;
use App\Services\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The signed-in account's own reports and complaints, and warnings sent to it */
class ReportController extends Controller
{
    /** jpg or png, up to 8 MB each */
    private const PHOTO_RULES = ['file', 'mimes:jpg,jpeg,png', 'max:8192'];

    /** @var Reports */
    private $reports;

    public function __construct(Reports $reports)
    {
        $this->reports = $reports;
    }

    public function store(Request $request): JsonResponse
    {
        $about = $request->input('about');
        $data = $request->validate([
            'about' => ['required', Rule::in(Report::SUBJECTS)],
            'provider' => ['nullable', 'integer'],
            'request' => ['nullable', 'integer'],
            'conversation' => ['nullable', 'integer'],
            'post' => ['nullable', 'string', 'max:36'],
            'comment' => ['nullable', 'integer'],
            'work' => ['nullable', 'string', 'max:36'],
            'reason' => ['required', Rule::in(Report::REASONS[$about] ?? [])],
            // A post or comment speaks for itself; other reports need the story
            'details' => in_array($about, Report::CONTENT_SUBJECTS, true) ? ['nullable', 'string', 'max:2000'] : ['required', 'string', 'min:10', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.Report::MAX_ATTACHMENTS],
            'photos.*' => self::PHOTO_RULES,
        ], $this->messages());

        $report = $this->reports->submit($request->user(), $data, $request->file('photos', []));

        return response()->json(['data' => $this->present($report->fresh())], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $reports = Report::where('reporter_id', $request->user()->getKey())
            ->with(['provider:id,display_name', 'subjectUser:id,name', 'serviceRequest:id,title'])
            ->latest()->limit(100)->get()
            ->map(function (Report $r) {
                return $this->present($r);
            });

        return response()->json(['data' => $reports]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reply' => ['required', 'string', 'min:3', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:'.Report::MAX_ATTACHMENTS],
            'photos.*' => self::PHOTO_RULES,
        ], $this->messages());

        $report = $this->reports->reply($request->user(), $id, $data['reply'], $request->file('photos', []));

        return response()->json(['data' => $this->present($report->fresh())]);
    }

    /** The reporter's own photo */
    public function file(Request $request, PrivateFiles $files, int $id, int $index)
    {
        $report = Report::where('reporter_id', $request->user()->getKey())->findOrFail($id);
        $file = ($report->attachments ?: [])[$index] ?? null;
        abort_unless($file, 404);

        return $files->response($file);
    }

    public function warnings(Request $request): JsonResponse
    {
        $warnings = AccountWarning::where('user_id', $request->user()->getKey())->latest()->limit(50)->get()
            ->map(function (AccountWarning $w) {
                return ['id' => $w->id, 'message' => $w->message, 'sent_at' => $w->created_at->toIso8601String(), 'read' => $w->read_at !== null];
            });

        return response()->json(['data' => $warnings]);
    }

    public function readWarning(Request $request, int $id): JsonResponse
    {
        $warning = AccountWarning::where('user_id', $request->user()->getKey())->findOrFail($id);
        if (! $warning->read_at) {
            $warning->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['data' => ['id' => $warning->id, 'read' => true]]);
    }

    /** What the reporter sees: never the safety team's notes, or who handled it */
    private function present(Report $r): array
    {
        return [
            'id' => $r->id,
            'about' => $r->subject_type,
            'about_name' => $r->subject_type === Report::SUBJECT_PROVIDER
                ? optional($r->provider)->display_name
                : ($r->subject_type === Report::SUBJECT_CUSTOMER ? $this->firstName(optional($r->subjectUser)->name)
                : (in_array($r->subject_type, Report::COMMUNITY_SUBJECTS, true) ? 'A community '.$r->subject_type
                : ($r->subject_type === Report::SUBJECT_WORK ? 'Work on '.(optional($r->provider)->display_name ?: 'a provider profile') : null))),
            'request' => $r->serviceRequest ? ['id' => $r->serviceRequest->id, 'title' => $r->serviceRequest->title] : null,
            'reason' => $r->reason,
            'details' => $r->details,
            'photos' => $r->publicAttachments(),
            'status' => $r->status,
            'question' => $r->status === Report::STATUS_INFO_REQUESTED ? $r->info_request : null,
            'your_reply' => $r->reporter_reply,
            'outcome_message' => $r->isOpen() ? null : $r->outcome_message,
            'sent_at' => $r->created_at->toIso8601String(),
            'resolved_at' => optional($r->resolved_at)->toIso8601String(),
        ];
    }

    private function firstName(?string $name): ?string
    {
        return $name ? explode(' ', trim($name))[0] : null;
    }

    private function messages(): array
    {
        return [
            'details.required' => 'Tell us what happened.',
            'details.min' => 'Tell us a little more about what happened.',
            'photos.max' => 'Add up to '.Report::MAX_ATTACHMENTS.' photos.',
            'photos.*.mimes' => 'Photos must be JPG or PNG.',
            'photos.*.max' => 'Each photo can be up to 8 MB.',
            'reason.required' => 'Choose what the problem is.',
            'reason.in' => 'Choose what the problem is.',
        ];
    }
}
