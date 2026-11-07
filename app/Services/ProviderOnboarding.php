<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\DocumentVerification;
use App\Models\LegalAcceptance;
use App\Models\ProviderApplication;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Start earning": one Send for everything a provider has ready, so they
 * never have to work out which of their two reviews is waiting.
 *
 * Sends the service details (ProviderApplications) and the documents
 * (DocumentVerifications) together. A first application must include the
 * documents: if either part isn't ready, nothing is sent. Corrections WorkMate
 * asked for are sent the same way.
 *
 * Reviewers decide both together too: approving lists the provider with the
 * tick (their ID is always checked before they're listed), asking for changes
 * sends their messages back, and rejecting closes both.
 */
class ProviderOnboarding
{
    /** @var ProviderApplications */
    private $applications;

    /** @var DocumentVerifications */
    private $verifications;

    /** @var LegalDocuments */
    private $legal;

    public function __construct(ProviderApplications $applications, DocumentVerifications $verifications, LegalDocuments $legal)
    {
        $this->applications = $applications;
        $this->verifications = $verifications;
        $this->legal = $legal;
    }

    /** @return array{details: bool, documents: bool} what was sent */
    public function send(User $user, ?Request $request = null): array
    {
        return DB::transaction(function () use ($user, $request) {
            $profile = ProviderProfile::where('owner_id', $user->getKey())->lockForUpdate()->first();
            if (! $profile) {
                throw new BusinessRuleException('provider_not_started', 'Add your skills and where you work first.', 404);
            }

            $verification = $this->verifications->forProfile($profile);
            $details = in_array($profile->status, [ProviderProfile::STATUS_DRAFT, ProviderProfile::STATUS_MORE_INFO_REQUIRED], true);
            $documents = in_array($verification->status, [DocumentVerification::STATUS_DRAFT, DocumentVerification::STATUS_INFO_REQUESTED], true);

            if (! $details && ! $documents) {
                throw new BusinessRuleException('nothing_to_send', 'Everything you added is already with WorkMate.', 409);
            }
            // A first application is checked together with the ID
            if ($profile->status === ProviderProfile::STATUS_DRAFT && ! $documents) {
                throw new BusinessRuleException('documents_not_ready', 'Your documents are still being handled. Wait for that decision before sending again.', 409);
            }

            if ($details) {
                $this->applications->submit($user);
            }
            if ($documents) {
                $this->verifications->submit($user);
            }

            $agreement = $this->legal->providerAgreement();
            if ($agreement) {
                $this->legal->accept($user, [$agreement->id], LegalAcceptance::METHOD_PROVIDER_APPLICATION, $request);
            }

            return ['details' => $details, 'documents' => $documents];
        });
    }

    /* ---------------- Reviewers ---------------- */

    /** Accepts the documents and, when they're waiting, the service details: the provider goes live */
    public function approve(DocumentVerification $verification, User $admin, ?string $note = null): void
    {
        DB::transaction(function () use ($verification, $admin, $note) {
            $profile = ProviderProfile::whereKey($verification->provider_profile_id)->lockForUpdate()->firstOrFail();
            if (in_array($profile->status, [ProviderProfile::STATUS_DRAFT, ProviderProfile::STATUS_MORE_INFO_REQUIRED], true)) {
                throw new BusinessRuleException('details_not_sent', 'The service details are with the provider for changes. Wait until they send them back.', 409);
            }

            // Documents approved earlier (a provider resending only their details) stay approved
            if ($verification->fresh()->status !== DocumentVerification::STATUS_APPROVED) {
                $this->verifications->approve($verification, $admin, $note);
            }
            $application = $this->pendingApplication($profile);
            if ($application) {
                $this->applications->approve($application, $admin, $note);
            }
        });
    }

    /**
     * Sends corrections back before a decision. Each message goes to its part;
     * documents sent back one by one count as document corrections.
     */
    public function requestChanges(DocumentVerification $verification, User $admin, ?string $details, ?string $documents): void
    {
        DB::transaction(function () use ($verification, $admin, $details, $documents) {
            $profile = ProviderProfile::whereKey($verification->provider_profile_id)->lockForUpdate()->firstOrFail();
            $verification = DocumentVerification::whereKey($verification->id)->firstOrFail();
            $sentBack = $verification->currentDocuments()->where('status', VerificationDocument::STATUS_REPLACEMENT_REQUIRED)->exists();
            $application = $this->pendingApplication($profile);

            if ($details && ! $application) {
                throw new BusinessRuleException('details_not_waiting', 'The service details aren’t waiting for review, so there’s nothing to send back.', 409);
            }
            if (! $details && ! $documents && ! $sentBack) {
                throw new BusinessRuleException('nothing_to_correct', 'Say what needs changing, or send back a document first.', 422);
            }

            if ($documents || $sentBack) {
                $this->verifications->requestInformation($verification, $admin, $documents ?: 'Replace the documents sent back, then send them again.');
            }
            if ($details) {
                $this->applications->requestMoreInformation($application, $admin, $details);
            }
        });
    }

    public function reject(DocumentVerification $verification, User $admin, string $reason): void
    {
        DB::transaction(function () use ($verification, $admin, $reason) {
            $profile = ProviderProfile::whereKey($verification->provider_profile_id)->lockForUpdate()->firstOrFail();
            $this->verifications->reject($verification, $admin, $reason);
            $application = $this->pendingApplication($profile);
            if ($application) {
                $this->applications->reject($application, $admin, $reason);
            }
        });
    }

    private function pendingApplication(ProviderProfile $profile): ?ProviderApplication
    {
        if ($profile->status !== ProviderProfile::STATUS_PENDING_REVIEW) {
            return null;
        }

        return $profile->applications()->where('status', ProviderApplication::STATUS_PENDING_REVIEW)->latest('id')->first();
    }
}
