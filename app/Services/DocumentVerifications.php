<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\DocumentRequest;
use App\Models\DocumentVerification;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Document verification: the National ID (front and back, always needed), the
 * documents their main profession needs (see DocumentRequirements), up to five
 * supporting documents of the provider's choice, and any extra documents an
 * administrator asks for. Approval gives the grey tick.
 *
 *   draft → pending_review → under_review → approved
 *                 ↑               ↓     ↘
 *             (provider)   info_requested  rejected → draft (provider changes documents)
 *
 * Files go to the private local disk. Photos are re-drawn as JPEG, which
 * strips hidden data such as GPS positions; PDFs are kept as uploaded.
 * Replaced files are kept as a record of what was reviewed.
 */
class DocumentVerifications
{
    public const DIRECTORY = 'verification';

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var PrivateFiles */
    private $files;

    /** @var DocumentRequirements */
    private $requirements;

    public function __construct(AuditLogger $audit, EventNotifications $notify, PrivateFiles $files, DocumentRequirements $requirements)
    {
        $this->audit = $audit;
        $this->notify = $notify;
        $this->files = $files;
        $this->requirements = $requirements;
    }

    public function forProfile(ProviderProfile $profile): DocumentVerification
    {
        $verification = DocumentVerification::where('provider_profile_id', $profile->getKey())->first();
        if (! $verification) {
            $verification = new DocumentVerification();
            $verification->forceFill(['provider_profile_id' => $profile->getKey()])->save();
        }

        return $verification;
    }

    /* ---------------- The provider ---------------- */

    /** A document the provider's profession needs, as it applies to them now, or null */
    public function requirement(User $user, string $key): ?array
    {
        return $this->requirements->spec($this->forProfile($this->ownProfile($user)), $key);
    }

    /** Answers to questions such as "Do you use your own vehicle?" */
    public function answer(User $user, array $answers): DocumentVerification
    {
        return DB::transaction(function () use ($user, $answers) {
            $verification = $this->ownVerificationForUpdate($user);
            $this->guardEditable($verification);

            $before = $verification->answers ?: [];
            $verification->forceFill(['answers' => array_merge($before, $answers)])->save();
            $this->audit->record('verification.answers_changed', $verification, $before, $verification->answers, null, $user);

            return $verification;
        });
    }

    /**
     * Adds a document, or replaces one when $replacing is given.
     *
     * @param  array{kind: string, label: ?string, request: ?int, requirement: ?string, expires_on: ?string}  $data
     * @param  array<int, array{0: UploadedFile, 1: ?string}>  $files  each file with its side (front/back) or null
     */
    public function upload(User $user, array $data, array $files, ?VerificationDocument $replacing = null): VerificationDocument
    {
        $profile = $this->ownProfile($user);
        $stored = array_map(function ($entry) {
            return $this->store($entry[0], $entry[1]);
        }, $files);

        try {
            return $this->saveUpload($user, $profile, $data, $stored, $replacing);
        } catch (\Throwable $e) {
            // Nothing refers to the new files if saving failed
            Storage::disk('local')->delete(array_column($stored, 'path'));
            throw $e;
        }
    }

    private function saveUpload(User $user, ProviderProfile $profile, array $data, array $stored, ?VerificationDocument $replacing): VerificationDocument
    {
        return DB::transaction(function () use ($user, $profile, $data, $stored, $replacing) {
            $verification = $this->forProfile($profile);
            $verification = DocumentVerification::whereKey($verification->id)->lockForUpdate()->first();
            $current = $verification->currentDocuments()->get();

            if ($replacing) {
                if ($replacing->document_verification_id !== $verification->id || ! in_array($replacing->status, [VerificationDocument::STATUS_AWAITING_REVIEW, VerificationDocument::STATUS_REPLACEMENT_REQUIRED], true)) {
                    throw new BusinessRuleException('document_not_replaceable', 'This document can’t be replaced.', 409);
                }
                // A document sent back can be replaced even while the rest is locked
                if (! $verification->isEditable() && $replacing->status !== VerificationDocument::STATUS_REPLACEMENT_REQUIRED) {
                    throw new BusinessRuleException('verification_locked', $this->lockedMessage($verification), 409);
                }
                $data = ['kind' => $replacing->kind, 'label' => $replacing->label, 'request' => $replacing->document_request_id, 'requirement' => $replacing->requirement_key] + $data;
            } else {
                $this->guardEditable($verification);
            }

            $request = null;
            if ($data['kind'] === VerificationDocument::KIND_NATIONAL_ID) {
                $data['label'] = 'National ID';
                $replacing = $replacing ?: $current->firstWhere('kind', VerificationDocument::KIND_NATIONAL_ID);
            } elseif ($data['kind'] === VerificationDocument::KIND_REQUESTED) {
                $request = $verification->requests()->whereKey($data['request'] ?? 0)->first();
                if (! $request || ($request->status !== DocumentRequest::STATUS_OPEN && ! $replacing)) {
                    throw new BusinessRuleException('request_not_open', 'WorkMate isn’t waiting for this document any more.', 409);
                }
                $data['label'] = $request->label;
            } elseif ($data['kind'] === VerificationDocument::KIND_PROFESSION) {
                $spec = $this->requirements->spec($verification, (string) ($data['requirement'] ?? ''));
                if (! $spec) {
                    throw new BusinessRuleException('requirement_not_needed', 'This document isn’t needed for your profession any more.', 422);
                }
                $added = $current->where('kind', VerificationDocument::KIND_PROFESSION)->where('requirement_key', $spec['id'])->count();
                if (! $replacing && $added >= $spec['count']) {
                    throw new BusinessRuleException('too_many_documents', 'You’ve already added '.($spec['count'] === 1 ? 'this document' : 'all '.$spec['count'].' of these').'. Remove one first.', 422);
                }
                $data['label'] = $spec['name'];
                $data['requirement'] = $spec['id'];
            } elseif (! $replacing && $current->where('kind', VerificationDocument::KIND_SUPPORTING)->count() >= DocumentVerification::MAX_SUPPORTING) {
                throw new BusinessRuleException('too_many_documents', 'You can add up to '.DocumentVerification::MAX_SUPPORTING.' supporting documents. Remove one first.', 422);
            }

            if ($replacing) {
                $replacing->forceFill(['status' => VerificationDocument::STATUS_REPLACED])->save();
            }

            $document = new VerificationDocument();
            $document->forceFill([
                'uuid' => (string) Str::uuid(),
                'document_verification_id' => $verification->id,
                'kind' => $data['kind'],
                'requirement_key' => $data['kind'] === VerificationDocument::KIND_PROFESSION ? $data['requirement'] : null,
                'label' => trim($data['label']),
                'document_request_id' => $request ? $request->id : null,
                'files' => $stored,
                'expires_on' => $data['expires_on'] ?? null,
                'status' => VerificationDocument::STATUS_AWAITING_REVIEW,
            ])->save();

            if ($request && $request->status === DocumentRequest::STATUS_OPEN) {
                $request->forceFill(['status' => DocumentRequest::STATUS_FULFILLED, 'fulfilled_at' => now()])->save();
            }
            // Changing documents after a rejection starts a new application
            if ($verification->status === DocumentVerification::STATUS_REJECTED) {
                $verification->forceFill(['status' => DocumentVerification::STATUS_DRAFT, 'decision_reason' => null, 'requirement_version_id' => null])->save();
            }

            $this->audit->record($replacing ? 'verification.document_replaced' : 'verification.document_added', $document, [], [
                'kind' => $document->kind, 'label' => $document->label, 'files' => count($stored),
            ], null, $user);

            return $document;
        });
    }

    /** Takes a supporting or profession document out before it's reviewed */
    public function remove(User $user, VerificationDocument $document): void
    {
        DB::transaction(function () use ($user, $document) {
            $verification = $this->ownVerificationForUpdate($user);
            $this->guardEditable($verification);

            if ($document->document_verification_id !== $verification->id || ! in_array($document->kind, [VerificationDocument::KIND_SUPPORTING, VerificationDocument::KIND_PROFESSION], true)
                || $document->status === VerificationDocument::STATUS_ACCEPTED || in_array($document->status, [VerificationDocument::STATUS_REPLACED, VerificationDocument::STATUS_REMOVED], true)) {
                throw new BusinessRuleException('document_not_removable', 'Only a document you added that hasn’t been accepted can be removed.', 409);
            }

            $document->forceFill(['status' => VerificationDocument::STATUS_REMOVED])->save();
            $this->audit->record('verification.document_removed', $document, [], ['label' => $document->label], null, $user);
        });
    }

    public function submit(User $user): DocumentVerification
    {
        if (! $user->hasVerifiedEmail()) {
            throw new BusinessRuleException('email_not_verified', 'Confirm your email address before sending your documents.', 403);
        }

        return DB::transaction(function () use ($user) {
            $verification = $this->ownVerificationForUpdate($user);
            $documents = $verification->currentDocuments()->get();
            $id = $documents->firstWhere('kind', VerificationDocument::KIND_NATIONAL_ID);

            if (! in_array($verification->status, [DocumentVerification::STATUS_DRAFT, DocumentVerification::STATUS_INFO_REQUESTED], true)) {
                throw new BusinessRuleException('verification_not_submittable', $this->lockedMessage($verification), 409);
            }
            if (! $id) {
                throw new BusinessRuleException('national_id_missing', 'Add both sides of your National ID first.', 422);
            }
            $version = $this->requirements->versionFor($verification);
            foreach ($this->requirements->questions($verification, $version) as $q) {
                if ($q['answer'] === null) {
                    throw new BusinessRuleException('questions_unanswered', 'Answer “'.$q['question'].'” first.', 422);
                }
            }
            $missing = $this->requirements->missing($verification, $documents, $version);
            if ($missing) {
                throw new BusinessRuleException('documents_missing', 'Add your '.implode(', ', $missing).' first.', 422);
            }
            if ($documents->where('status', VerificationDocument::STATUS_REPLACEMENT_REQUIRED)->isNotEmpty()) {
                throw new BusinessRuleException('replacements_missing', 'Replace the documents WorkMate sent back first.', 422);
            }
            if ($verification->requests()->where('status', DocumentRequest::STATUS_OPEN)->exists()) {
                throw new BusinessRuleException('requests_open', 'Add the documents WorkMate asked for first.', 422);
            }

            $before = $verification->status;
            $verification->forceFill([
                // Sent under these rules from now on, even if they change
                'requirement_version_id' => $before === DocumentVerification::STATUS_DRAFT ? optional($version)->id : $verification->requirement_version_id,
                'status' => DocumentVerification::STATUS_PENDING_REVIEW,
                'submitted_at' => $verification->submitted_at ?: now(),
                'decision_reason' => null,
            ])->save();

            $this->audit->record($before === DocumentVerification::STATUS_DRAFT ? 'verification.submitted' : 'verification.resubmitted',
                $verification, ['status' => $before], ['status' => $verification->status, 'documents' => $documents->count()], null, $user);

            return $verification;
        });
    }

    /* ---------------- Administrators ---------------- */

    public function assign(DocumentVerification $verification, User $admin): DocumentVerification
    {
        return $this->change($verification, $admin, function (DocumentVerification $v) use ($admin) {
            $this->guardOpen($v);
            $v->forceFill(['reviewer_id' => $admin->getKey(), 'status' => $this->underReview($v)])->save();

            return ['verification.assigned', $v, null];
        });
    }

    public function acceptDocument(DocumentVerification $verification, VerificationDocument $document, User $admin): DocumentVerification
    {
        return $this->reviewDocument($verification, $document, $admin, VerificationDocument::STATUS_ACCEPTED, null);
    }

    public function sendBackDocument(DocumentVerification $verification, VerificationDocument $document, User $admin, string $reason): DocumentVerification
    {
        return $this->reviewDocument($verification, $document, $admin, VerificationDocument::STATUS_REPLACEMENT_REQUIRED, $reason);
    }

    /** Asks the provider for one more document, such as a permit their profession needs */
    public function requestDocument(DocumentVerification $verification, User $admin, string $label, ?string $instructions): DocumentVerification
    {
        return $this->change($verification, $admin, function (DocumentVerification $v) use ($admin, $label, $instructions) {
            $this->guardOpen($v);
            $request = new DocumentRequest();
            $request->forceFill([
                'document_verification_id' => $v->id, 'label' => trim($label), 'instructions' => $instructions,
                'status' => DocumentRequest::STATUS_OPEN, 'requested_by' => $admin->getKey(),
            ])->save();
            $v->forceFill(['status' => DocumentVerification::STATUS_INFO_REQUESTED, 'reviewer_id' => $v->reviewer_id ?: $admin->getKey()])->save();

            $this->notify->verificationChanged($v, 'requested');

            return ['verification.document_requested', $v, $label.($instructions ? ': '.$instructions : '')];
        });
    }

    public function requestInformation(DocumentVerification $verification, User $admin, string $reason): DocumentVerification
    {
        return $this->change($verification, $admin, function (DocumentVerification $v) use ($admin, $reason) {
            $this->guardOpen($v);
            $v->forceFill(['status' => DocumentVerification::STATUS_INFO_REQUESTED, 'decision_reason' => $reason, 'reviewer_id' => $v->reviewer_id ?: $admin->getKey()])->save();

            $this->notify->verificationChanged($v, 'info_requested');

            return ['verification.info_requested', $v, $reason];
        });
    }

    public function approve(DocumentVerification $verification, User $admin, ?string $note): DocumentVerification
    {
        return $this->change($verification, $admin, function (DocumentVerification $v) use ($admin, $note) {
            if (! in_array($v->status, [DocumentVerification::STATUS_PENDING_REVIEW, DocumentVerification::STATUS_UNDER_REVIEW], true)) {
                throw new BusinessRuleException('verification_not_reviewable', 'These documents aren’t waiting for a decision.', 409);
            }
            $documents = $v->currentDocuments()->get();
            $id = $documents->firstWhere('kind', VerificationDocument::KIND_NATIONAL_ID);
            if (! $id || $id->status !== VerificationDocument::STATUS_ACCEPTED) {
                throw new BusinessRuleException('national_id_not_accepted', 'Accept the National ID before approving.', 409);
            }
            if ($documents->where('status', '!=', VerificationDocument::STATUS_ACCEPTED)->isNotEmpty()) {
                throw new BusinessRuleException('documents_not_reviewed', 'Accept or send back every document before approving.', 409);
            }
            if ($v->requests()->where('status', DocumentRequest::STATUS_OPEN)->exists()) {
                throw new BusinessRuleException('requests_open', 'The provider hasn’t added every requested document yet.', 409);
            }
            if ($this->requirements->missing($v, $documents)) {
                throw new BusinessRuleException('documents_missing', 'The provider hasn’t added every document their profession needs.', 409);
            }

            $v->forceFill(['status' => DocumentVerification::STATUS_APPROVED, 'decided_at' => now(), 'decided_by' => $admin->getKey(), 'decision_reason' => null])->save();
            $v->profile->forceFill(['document_verified_at' => now()])->save();

            $this->notify->verificationChanged($v, 'approved');

            return ['verification.approved', $v, $note];
        });
    }

    public function reject(DocumentVerification $verification, User $admin, string $reason): DocumentVerification
    {
        return $this->change($verification, $admin, function (DocumentVerification $v) use ($admin, $reason) {
            $this->guardOpen($v);
            $v->forceFill(['status' => DocumentVerification::STATUS_REJECTED, 'decided_at' => now(), 'decided_by' => $admin->getKey(), 'decision_reason' => $reason])->save();
            $v->profile->forceFill(['document_verified_at' => null])->save();

            $this->notify->verificationChanged($v, 'rejected');

            return ['verification.rejected', $v, $reason];
        });
    }

    /** Back to review. The tick stays until the new decision. */
    public function reopen(DocumentVerification $verification, User $admin, string $reason): DocumentVerification
    {
        return $this->change($verification, $admin, function (DocumentVerification $v) use ($reason) {
            if (! in_array($v->status, [DocumentVerification::STATUS_APPROVED, DocumentVerification::STATUS_REJECTED], true)) {
                throw new BusinessRuleException('verification_not_decided', 'Only a decided application can be reopened.', 409);
            }
            $v->forceFill(['status' => DocumentVerification::STATUS_UNDER_REVIEW, 'decided_at' => null, 'decided_by' => null])->save();

            return ['verification.reopened', $v, $reason];
        });
    }

    /** A note for other reviewers, kept in the audit log */
    public function note(DocumentVerification $verification, User $admin, string $note): void
    {
        $this->audit->record('verification.note_added', $verification, [], [], $note, $admin);
    }

    /** Records that an administrator opened a private file */
    public function recordView(VerificationDocument $document, int $index, User $admin): void
    {
        $this->audit->record('document.viewed', $document, [], ['label' => $document->label, 'file' => $index], 'Verification review', $admin);
    }

    /* ---------------- Helpers ---------------- */

    private function reviewDocument(DocumentVerification $verification, VerificationDocument $document, User $admin, string $status, ?string $reason): DocumentVerification
    {
        return $this->change($verification, $admin, function (DocumentVerification $v) use ($document, $admin, $status, $reason) {
            $this->guardOpen($v);
            $document = VerificationDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->document_verification_id !== $v->id || in_array($document->status, [VerificationDocument::STATUS_REPLACED, VerificationDocument::STATUS_REMOVED], true)) {
                throw new BusinessRuleException('document_not_current', 'This document has been replaced or removed.', 409);
            }

            $document->forceFill(['status' => $status, 'review_note' => $reason, 'reviewed_by' => $admin->getKey(), 'reviewed_at' => now()])->save();
            $v->forceFill(['status' => $this->underReview($v), 'reviewer_id' => $v->reviewer_id ?: $admin->getKey()])->save();

            if ($status === VerificationDocument::STATUS_REPLACEMENT_REQUIRED) {
                $this->notify->verificationChanged($v, 'sent_back');
            }
            $this->audit->record($status === VerificationDocument::STATUS_ACCEPTED ? 'verification.document_accepted' : 'verification.document_sent_back',
                $document, [], ['label' => $document->label, 'status' => $status], $reason, $admin);

            return [null, $v, null];
        });
    }

    /** Runs an administrator's change with the record locked, then audits it */
    private function change(DocumentVerification $verification, User $admin, callable $apply): DocumentVerification
    {
        return DB::transaction(function () use ($verification, $admin, $apply) {
            $v = DocumentVerification::whereKey($verification->id)->lockForUpdate()->firstOrFail();
            $before = $v->status;

            if ($v->profile->hasMember($admin)) {
                throw new BusinessRuleException('conflict_of_interest', 'You cannot review documents for a provider you belong to.', 403);
            }

            [$action, $v, $reason] = $apply($v);
            if ($action) {
                $this->audit->record($action, $v, ['status' => $before], ['status' => $v->status], $reason, $admin);
            }

            return $v;
        });
    }

    private function underReview(DocumentVerification $v): string
    {
        return $v->status === DocumentVerification::STATUS_PENDING_REVIEW ? DocumentVerification::STATUS_UNDER_REVIEW : $v->status;
    }

    private function guardOpen(DocumentVerification $v): void
    {
        if (! in_array($v->status, DocumentVerification::OPEN_STATUSES, true)) {
            throw new BusinessRuleException('verification_not_reviewable', 'These documents aren’t waiting for review.', 409);
        }
    }

    private function guardEditable(DocumentVerification $v): void
    {
        if (! $v->isEditable()) {
            throw new BusinessRuleException('verification_locked', $this->lockedMessage($v), 409);
        }
    }

    private function ownProfile(User $user): ProviderProfile
    {
        $profile = ProviderProfile::where('owner_id', $user->getKey())->first();

        if (! $profile) {
            throw new BusinessRuleException('provider_not_started', 'Add your service details first.', 404);
        }
        if ($profile->status === ProviderProfile::STATUS_SUSPENDED) {
            throw new BusinessRuleException('provider_suspended', 'Your provider account is suspended. Contact WorkMate support.', 403);
        }

        return $profile;
    }

    private function ownVerificationForUpdate(User $user): DocumentVerification
    {
        $verification = $this->forProfile($this->ownProfile($user));

        return DocumentVerification::whereKey($verification->id)->lockForUpdate()->first();
    }

    private function lockedMessage(DocumentVerification $v): string
    {
        $messages = [
            DocumentVerification::STATUS_PENDING_REVIEW => 'Your documents are being reviewed. You can make changes once WorkMate has decided.',
            DocumentVerification::STATUS_UNDER_REVIEW => 'Your documents are being reviewed. You can make changes once WorkMate has decided.',
            DocumentVerification::STATUS_APPROVED => 'Your documents are already approved.',
        ];

        return $messages[$v->status] ?? 'This action is not available right now.';
    }

    /** Saves one file privately and returns its details */
    private function store(UploadedFile $file, ?string $side): array
    {
        return ['side' => $side] + $this->files->store($file, self::DIRECTORY);
    }
}
