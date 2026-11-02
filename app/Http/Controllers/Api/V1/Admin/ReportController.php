<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\AuditLogger;
use App\Services\PrivateFiles;
use App\Services\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The safety team working a report */
class ReportController extends Controller
{
    /** @var Reports */
    private $reports;

    public function __construct(Reports $reports)
    {
        $this->reports = $reports;
    }

    public function assign(Request $request, Report $report): JsonResponse
    {
        return $this->done($this->reports->assign($report, $request->user()));
    }

    public function requestInformation(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->done($this->reports->requestInformation($report, $request->user(), $data['question']));
    }

    public function note(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->reports->note($report, $request->user(), $data['note']);

        return $this->done($report);
    }

    public function resolve(Request $request, Report $report): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in(Report::OUTCOMES)],
            'message' => ['required', 'string', 'min:5', 'max:1000'],
            'warning' => ['required_if:outcome,warned', 'nullable', 'string', 'min:10', 'max:1000'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'message.required' => 'Write what the reporter will be told.',
            'warning.required_if' => 'Write the warning the reported person will see.',
        ]);

        return $this->done($this->reports->resolve($report, $request->user(), $data));
    }

    /** Opens an evidence photo. Every opening is recorded. */
    public function file(Request $request, PrivateFiles $files, AuditLogger $audit, Report $report, int $index)
    {
        $file = ($report->attachments ?: [])[$index] ?? null;
        abort_unless($file, 404);
        $audit->record('trust.report_photo_viewed', $report, [], ['photo' => $index], 'Report review', $request->user());

        return $files->response($file);
    }

    private function done(Report $report): JsonResponse
    {
        return response()->json(['data' => ['status' => $report->fresh()->status]]);
    }
}
