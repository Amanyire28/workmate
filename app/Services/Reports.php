<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AccountWarning;
use App\Models\Conversation;
use App\Models\ProviderProfile;
use App\Models\Report;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Reports and complaints. Customers report providers, providers report
 * customers, and anyone can report something else that went wrong. The
 * reporter follows it in their account; the safety team (trust.view) asks
 * for more information, notes and resolves it, and the outcome's action is
 * carried out here: a warning, a provider suspension or an account
 * suspension. The reported person is never told who reported them.
 *
 *   open → under_review ⇄ info_requested → resolved | closed
 */
class Reports
{
    public const DIRECTORY = 'reports';

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var PrivateFiles */
    private $files;

    public function __construct(AuditLogger $audit, EventNotifications $notify, PrivateFiles $files)
    {
        $this->audit = $audit;
        $this->notify = $notify;
        $this->files = $files;
    }

    /* ---------------- The reporter ---------------- */

    /**
     * @param  array{about: string, provider: ?int, request: ?int, conversation: ?int, reason: string, details: ?string}  $data
     * @param  UploadedFile[]  $photos
     */
    public function submit(User $reporter, array $data, array $photos = []): Report
    {
        $subject = $this->resolveSubject($reporter, $data);
        $stored = $this->storePhotos($photos);

        try {
            return DB::transaction(function () use ($reporter, $data, $subject, $stored) {
                $report = new Report();
                $report->forceFill($subject + [
                    'reporter_id' => $reporter->getKey(),
                    'subject_type' => $data['about'],
                    'reason' => $data['reason'],
                    'details' => isset($data['details']) ? trim($data['details']) : null,
                    'attachments' => $stored ?: null,
                    'severity' => $this->severity($data['reason']),
                    'status' => Report::STATUS_OPEN,
                ])->save();

                $this->audit->record('trust.report_received', $report, [], [
                    'about' => $report->subject_type, 'reason' => $report->reason, 'photos' => count($stored),
                ], null, $reporter);

                return $report;
            });
        } catch (\Throwable $e) {
            $this->files->discard(array_column($stored, 'path'));
            throw $e;
        }
    }

    /** The reporter answers the safety team's question, optionally with more photos */
    public function reply(User $reporter, int $reportId, string $text, array $photos = []): Report
    {
        $report = Report::where('reporter_id', $reporter->getKey())->findOrFail($reportId);
        if ($report->status !== Report::STATUS_INFO_REQUESTED) {
            throw new BusinessRuleException('report_not_waiting', 'WorkMate isn’t waiting for more information on this report.', 409);
        }
        if (count($report->attachments ?: []) + count($photos) > Report::MAX_ATTACHMENTS) {
            throw new BusinessRuleException('too_many_photos', 'A report can have up to '.Report::MAX_ATTACHMENTS.' photos.', 422);
        }
        $stored = $this->storePhotos($photos);

        return DB::transaction(function () use ($report, $reporter, $text, $stored) {
            $report = Report::whereKey($report->id)->lockForUpdate()->first();
            $report->forceFill([
                'reporter_reply' => trim($text),
                'attachments' => array_merge($report->attachments ?: [], $stored) ?: null,
                'status' => Report::STATUS_UNDER_REVIEW,
            ])->save();
            $this->audit->record('trust.report_reply', $report, ['status' => Report::STATUS_INFO_REQUESTED], ['status' => $report->status, 'photos' => count($stored)], null, $reporter);

            return $report;
        });
    }

    /* ---------------- The safety team ---------------- */

    public function assign(Report $report, User $admin): Report
    {
        return $this->change($report, $admin, function (Report $r) use ($admin) {
            $r->forceFill(['assigned_to' => $admin->getKey(), 'status' => $r->status === Report::STATUS_OPEN ? Report::STATUS_UNDER_REVIEW : $r->status])->save();

            return ['trust.report_assigned', null];
        });
    }

    /** Asks the reporter a question; they answer from their account */
    public function requestInformation(Report $report, User $admin, string $question): Report
    {
        return $this->change($report, $admin, function (Report $r) use ($admin, $question) {
            $r->forceFill(['status' => Report::STATUS_INFO_REQUESTED, 'info_request' => trim($question), 'reporter_reply' => null, 'assigned_to' => $r->assigned_to ?: $admin->getKey()])->save();
            $this->notify->reportChanged($r, 'info_requested');

            return ['trust.report_info_requested', $question];
        });
    }

    public function note(Report $report, User $admin, string $note): void
    {
        $this->audit->record('trust.report_note', $report, [], [], $note, $admin);
    }

    /**
     * Closes the report with an outcome and carries out its action.
     *
     * @param  array{outcome: string, message: string, warning: ?string, reason: string}  $data
     */
    public function resolve(Report $report, User $admin, array $data): Report
    {
        return $this->change($report, $admin, function (Report $r) use ($admin, $data) {
            $outcome = $data['outcome'];
            $target = $this->targetAccount($r);
            if (in_array($outcome, ['warned', 'account_suspended'], true) && ! $target) {
                throw new BusinessRuleException('no_reported_person', 'This report isn’t about a particular person.', 422);
            }
            if ($outcome === 'provider_suspended' && ! $r->provider) {
                throw new BusinessRuleException('no_reported_provider', 'This report isn’t about a provider.', 422);
            }
            if ($target && $target->is($admin)) {
                throw new BusinessRuleException('conflict_of_interest', 'You can’t act on a report about yourself.', 403);
            }

            if ($outcome === 'warned') {
                $warning = new AccountWarning();
                $warning->forceFill(['user_id' => $target->id, 'report_id' => $r->id, 'message' => trim($data['warning']), 'issued_by' => $admin->getKey()])->save();
                $this->audit->record('trust.warning_sent', $target, [], ['report_id' => $r->id], $data['reason'], $admin);
                $this->notify->warningIssued($warning);
            } elseif ($outcome === 'provider_suspended') {
                app(ProviderApplications::class)->suspend($r->provider, $admin, $data['reason']);
            } elseif ($outcome === 'account_suspended') {
                app(AdminActions::class)->setAccountStatus($target, User::STATUS_SUSPENDED, $admin, $data['reason']);
            }

            $r->forceFill([
                'status' => $outcome === 'no_violation' ? Report::STATUS_CLOSED : Report::STATUS_RESOLVED,
                'outcome' => $outcome,
                'outcome_message' => trim($data['message']),
                'resolved_by' => $admin->getKey(),
                'resolved_at' => now(),
                'assigned_to' => $r->assigned_to ?: $admin->getKey(),
            ])->save();
            $this->notify->reportChanged($r, 'resolved');

            return ['trust.report_resolved', $data['reason']];
        });
    }

    /* ---------------- Helpers ---------------- */

    /** Runs a safety-team change with the report locked, then audits it */
    private function change(Report $report, User $admin, callable $apply): Report
    {
        return DB::transaction(function () use ($report, $admin, $apply) {
            $r = Report::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if (! $r->isOpen()) {
                throw new BusinessRuleException('report_closed', 'This report has already been resolved.', 409);
            }
            if ($r->reporter_id === (int) $admin->getKey() || ($r->provider && $r->provider->hasMember($admin))) {
                throw new BusinessRuleException('conflict_of_interest', 'You can’t handle a report you made or one about a provider you belong to.', 403);
            }

            $before = $r->status;
            [$action, $reason] = $apply($r);
            $this->audit->record($action, $r, ['status' => $before], ['status' => $r->status, 'outcome' => $r->outcome], $reason, $admin);

            return $r;
        });
    }

    /**
     * Works out who the report is about, checking the reporter has a real
     * connection to them (a request or a chat). Nobody can report themselves.
     */
    private function resolveSubject(User $reporter, array $data): array
    {
        $request = null;
        if (! empty($data['request'])) {
            $request = ServiceRequest::withTrashed()->whereKey($data['request'])
                ->where(function ($q) use ($reporter) {
                    $q->where('customer_id', $reporter->getKey())->orWhere(function ($q) use ($reporter) {
                        $q->receivedBy($reporter);
                    });
                })->first();
            if (! $request) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
        }
        $conversation = null;
        if (! empty($data['conversation'])) {
            $conversation = Conversation::find($data['conversation']);
            if (! $conversation || ! $conversation->hasParticipant($reporter)) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
        }

        $base = ['service_request_id' => $request ? $request->id : null, 'conversation_id' => $conversation ? $conversation->id : null, 'reporter_role' => 'customer'];

        if ($data['about'] === Report::SUBJECT_PROVIDER) {
            $providerId = $data['provider'] ?? ($request ? $request->provider_profile_id : null) ?? ($conversation ? $conversation->provider_profile_id : null);
            $provider = $providerId ? ProviderProfile::find($providerId) : null;
            if (! $provider) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            if ($provider->hasMember($reporter)) {
                throw new BusinessRuleException('own_provider', 'You can’t report your own provider profile.', 422);
            }
            if (($request && $request->provider_profile_id !== $provider->id) || ($conversation && $conversation->provider_profile_id !== $provider->id)) {
                throw new BusinessRuleException('mismatch', 'That request or chat isn’t with this provider.', 422);
            }

            return $base + ['provider_profile_id' => $provider->id, 'subject_user_id' => $provider->owner_id];
        }

        if ($data['about'] === Report::SUBJECT_CUSTOMER) {
            // A provider reports a customer they've dealt with, through a request or a chat
            $customerId = $request ? $request->customer_id : ($conversation ? $conversation->customer_id : null);
            $providerId = $request ? $request->provider_profile_id : ($conversation ? $conversation->provider_profile_id : null);
            $asProvider = $providerId && ProviderProfile::find($providerId)->hasMember($reporter);
            if (! $customerId || ! $asProvider) {
                throw new BusinessRuleException('customer_unknown', 'You can report a customer from a request they sent you or a chat with them.', 422);
            }

            return ['reporter_role' => 'provider', 'provider_profile_id' => null, 'subject_user_id' => $customerId] + $base;
        }

        return $base + ['provider_profile_id' => null, 'subject_user_id' => null,
            'reporter_role' => $reporter->providerProfile && $reporter->providerProfile->isApproved() ? 'provider' : 'customer'];
    }

    /** The account a warning or suspension applies to */
    private function targetAccount(Report $r): ?User
    {
        return $r->subject_user_id ? User::withTrashed()->find($r->subject_user_id) : null;
    }

    /** @param UploadedFile[] $photos */
    private function storePhotos(array $photos): array
    {
        $stored = [];
        try {
            foreach ($photos as $photo) {
                $stored[] = $this->files->store($photo, self::DIRECTORY, false);
            }
        } catch (\Throwable $e) {
            $this->files->discard(array_column($stored, 'path'));
            throw $e;
        }

        return $stored;
    }

    private function severity(string $reason): string
    {
        if (in_array($reason, Report::HIGH_SEVERITY, true)) {
            return 'high';
        }

        return in_array($reason, Report::MEDIUM_SEVERITY, true) ? 'medium' : 'low';
    }
}
