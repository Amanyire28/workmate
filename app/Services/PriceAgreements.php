<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\JobPriceAgreement;
use App\Models\JobPricingEvent;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase B2: a price is PROPOSED by the provider business, and only the customer's explicit "Accept price"
 * makes it the agreed price. Nothing else does: not time passing, opening the page, or a notification.
 *
 *   provider proposes ─► pending_customer ─► agreed            (customer accepts; any earlier agreed price is superseded)
 *                                         ├─► revision_requested (customer asks for a change; the provider proposes a new version)
 *                                         ├─► rejected         (customer says no)
 *                                         ├─► expired          (the customer didn't answer in time; NEVER an agreement)
 *                                         ├─► cancelled        (the provider withdrew it, or the job was cancelled or done)
 *                                         └─► superseded       (the provider proposed a newer version)
 *
 * A revised price is always a NEW version; earlier versions are never edited (the model refuses). While a price
 * is agreed, the provider can propose an amendment: the agreed price stays in force until the customer accepts
 * the new version. Counter-offers are suggestions only: the customer may say what they would like, but only a
 * version the provider proposed can ever be accepted.
 *
 * Every action runs in one transaction with the request row locked, so two people acting at the same moment are
 * handled one after the other; unique "slot" columns in the database back that up. Repeating an action that
 * already happened changes nothing and notifies nobody twice. Amounts are whole UGX. This records an agreement
 * only: it charges nothing, takes no payment and creates no commission or debt.
 */
class PriceAgreements
{
    public const MIN_SCOPE = 10;
    public const MAX_SCOPE = 2000;
    public const MAX_EXCLUSIONS = 1000;
    public const MAX_NOTE = 300;

    /** The request must still be to be done for a price to be proposed or accepted */
    private const WORKABLE = [ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_SCHEDULED, ServiceRequest::STATUS_IN_PROGRESS];

    /** @var Pricing */
    private $pricing;

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var PlatformSettings */
    private $settings;

    public function __construct(Pricing $pricing, AuditLogger $audit, EventNotifications $notify, PlatformSettings $settings)
    {
        $this->pricing = $pricing;
        $this->audit = $audit;
        $this->notify = $notify;
        $this->settings = $settings;
    }

    /* ---------------- The provider business ---------------- */

    /**
     * Proposes a price (or a new version of one). Only the business's owner or a manager, for a request sent to
     * it or a job it was hired for. A version still waiting is superseded by the new one. A retry with the same
     * client_token returns the first version.
     *
     * @param  array{amount: int, scope: string, exclusions: ?string, client_token: ?string}  $data
     */
    public function propose(User $user, int $requestId, array $data): JobPriceAgreement
    {
        $this->guardEnabled();
        $amount = $this->amount($data['amount'] ?? null, 'amount_invalid', 'Enter the price in UGX, between UGX '.number_format(Pricing::MIN_AMOUNT).' and UGX '.number_format(Pricing::MAX_AMOUNT).'.');
        $scope = $this->text($data['scope'] ?? '', self::MAX_SCOPE);
        $exclusions = $this->text($data['exclusions'] ?? '', self::MAX_EXCLUSIONS);
        if (mb_strlen($scope) < self::MIN_SCOPE) {
            throw new BusinessRuleException('scope_required', 'Say what the price includes (at least '.self::MIN_SCOPE.' characters).', 422);
        }
        $exclusions = $exclusions === '' ? null : $exclusions;
        $token = isset($data['client_token']) && $data['client_token'] !== '' ? (string) $data['client_token'] : null;

        return DB::transaction(function () use ($user, $requestId, $amount, $scope, $exclusions, $token) {
            $request = $this->lockForProvider($user, $requestId);
            $provider = $request->provider;

            // The same try again (a dropped connection): the first one, with nothing new recorded or sent
            if ($token) {
                $same = JobPriceAgreement::where('service_request_id', $request->id)->where('client_token', $token)->first();
                if ($same) {
                    return $same;
                }
            }
            if (! in_array($request->status, self::WORKABLE, true)) {
                throw new BusinessRuleException('request_status_conflict', 'A price can only be proposed for a job that’s still to be done.', 409);
            }

            $open = $this->openFor($request);
            if ($open && $this->expireIfDue($open)) {
                $open = null;
            }
            $agreed = JobPriceAgreement::where('service_request_id', $request->id)->where('agreed_slot', 1)->lockForUpdate()->first();

            if ($open && $this->same($open, $amount, $scope, $exclusions)) {
                throw new BusinessRuleException('duplicate_proposal', 'That’s the same price you already proposed.', 409);
            }
            if ($agreed && $this->same($agreed, $amount, $scope, $exclusions)) {
                throw new BusinessRuleException('nothing_to_change', 'That’s the price already agreed. Change the price or what it covers to propose an amendment.', 422);
            }

            $before = $open ? $open->status : null;
            if ($open) {
                $this->move($open, JobPriceAgreement::SUPERSEDED, [], 'superseded', $user, 'provider', 'Replaced by a newer version');
            }

            $class = $this->classify($request, $provider);
            $now = now();
            $version = new JobPriceAgreement();
            $version->forceFill([
                'service_request_id' => $request->id,
                'provider_profile_id' => $provider->id,
                'revision_no' => (int) JobPriceAgreement::where('service_request_id', $request->id)->max('revision_no') + 1,
                'amount_ugx' => $amount,
                'scope_text' => $scope,
                'exclusions_text' => $exclusions,
                'status' => JobPriceAgreement::PENDING_CUSTOMER,
                'is_amendment' => $agreed !== null,
                'after_inspection' => $request->needsInspection(),
                'proposed_by_user_id' => $user->getKey(),
                'proposed_at' => $now,
                'expires_at' => $now->copy()->addDays($this->expiryDays()),
                'supersedes_id' => $open ? $open->id : (JobPriceAgreement::where('service_request_id', $request->id)->max('id') ?: null),
                'category_id' => $request->category_id,
                'area_id' => $request->area_id,
                'area_name' => $request->area_name,
                'profession_id' => $class['profession_id'],
                'profession_source' => $class['source'],
                'profession_review_needed' => $class['review_needed'],
                'client_token' => $token,
                'open_slot' => 1,
            ])->save();

            // A concrete price after an inspection: "inspection needed" is no longer true
            if ($request->needsInspection()) {
                $request->forceFill(['inspection_required_at' => null, 'inspection_note' => null])->save();
                $this->audit->record('marketplace.inspection_cleared', $request, ['inspection_required' => true], ['inspection_required' => false], 'A price was proposed', $user);
            }

            $this->record($request, $version, $before ? 'revised' : 'proposed', $user, 'provider', null, $version->status, $amount, $version->is_amendment ? 'Amendment to the agreed price' : null);
            $this->audit->record('marketplace.price_proposed', $version, [], ['service_request_id' => $request->id, 'revision' => $version->revision_no, 'amount_ugx' => $amount, 'amendment' => $version->is_amendment], null, $user);
            $this->notify->priceProposed($version, $request, $user, $version->revision_no > 1 || $before !== null);

            return $version;
        });
    }

    /** The provider takes a proposal back while the customer hasn't accepted it */
    public function withdraw(User $user, int $requestId, int $agreementId): JobPriceAgreement
    {
        $this->guardEnabled();

        return DB::transaction(function () use ($user, $requestId, $agreementId) {
            $request = $this->lockForProvider($user, $requestId);
            $a = $this->find($request, $agreementId);

            if ($a->status === JobPriceAgreement::CANCELLED && $a->closed_reason === 'provider_withdrew') {
                return $a;
            }
            if ($this->expireIfDue($a)) {
                throw new BusinessRuleException('proposal_expired', 'That price offer has already expired.', 409);
            }
            if (! $a->isOpen()) {
                throw new BusinessRuleException('already_decided', 'That price offer is no longer open.', 409);
            }

            $was = $a->status;
            $this->move($a, JobPriceAgreement::CANCELLED, ['closed_reason' => 'provider_withdrew'], 'withdrawn', $user, 'provider', null);
            if ($was === JobPriceAgreement::PENDING_CUSTOMER) {
                $this->notify->priceWithdrawn($a, $request, $user);
            }

            return $a;
        });
    }

    /* ---------------- The customer ---------------- */

    /**
     * "Accept price": the only way a price becomes agreed. The customer sends the amount they are looking at,
     * and it has to be the amount of the version that is still open; otherwise they are asked to look again.
     */
    public function accept(User $customer, int $requestId, int $agreementId, $amount): JobPriceAgreement
    {
        $this->guardEnabled();

        return DB::transaction(function () use ($customer, $requestId, $agreementId, $amount) {
            $request = $this->lockForCustomer($customer, $requestId);
            $a = $this->find($request, $agreementId);

            // Accepted already, by this customer: nothing more happens
            if ($a->status === JobPriceAgreement::AGREED && (int) $a->responded_by_user_id === (int) $customer->getKey()) {
                return $a;
            }
            $this->guardCanDecide($a);
            if (! is_numeric($amount) || (int) $amount !== (int) $a->amount_ugx) {
                throw new BusinessRuleException('stale_proposal', 'The price you are looking at is out of date. Please review the latest price.', 409);
            }
            if (! in_array($request->status, self::WORKABLE, true)) {
                throw new BusinessRuleException('request_status_conflict', 'This job is no longer open, so a price can’t be accepted.', 409);
            }
            if ($a->status !== JobPriceAgreement::PENDING_CUSTOMER) {
                throw new BusinessRuleException('already_decided', 'You asked for a change to this price. The provider will send a new price.', 409);
            }

            // An amendment replaces the price agreed before it, only now that the customer has accepted it
            $previous = JobPriceAgreement::where('service_request_id', $request->id)->where('agreed_slot', 1)->lockForUpdate()->first();
            if ($previous) {
                $this->move($previous, JobPriceAgreement::SUPERSEDED, ['closed_reason' => 'amended'], 'superseded', $customer, 'customer', 'Replaced by version '.$a->revision_no);
            }
            $this->move($a, JobPriceAgreement::AGREED, ['responded_by_user_id' => $customer->getKey(), 'responded_at' => now()], 'accepted', $customer, 'customer', null);
            $this->notify->priceAgreed($a, $request, $customer);

            return $a;
        });
    }

    /** "No thanks": the customer turns the price down. Nothing is agreed. */
    public function reject(User $customer, int $requestId, int $agreementId, ?string $note): JobPriceAgreement
    {
        $this->guardEnabled();
        $note = $this->note($note);

        return DB::transaction(function () use ($customer, $requestId, $agreementId, $note) {
            $request = $this->lockForCustomer($customer, $requestId);
            $a = $this->find($request, $agreementId);

            if ($a->status === JobPriceAgreement::REJECTED && (int) $a->responded_by_user_id === (int) $customer->getKey()) {
                return $a;
            }
            $this->guardCanDecide($a);

            $this->move($a, JobPriceAgreement::REJECTED, ['responded_by_user_id' => $customer->getKey(), 'responded_at' => now(), 'response_note' => $note], 'rejected', $customer, 'customer', $note);
            $this->notify->priceRejected($a, $request, $customer);

            return $a;
        });
    }

    /**
     * "Request a change": asks the provider for another price. It agrees nothing. The amount, if given, is only
     * what the customer would like: the provider still has to propose a new version for it to be accepted.
     */
    public function requestChange(User $customer, int $requestId, int $agreementId, ?string $note, $suggested): JobPriceAgreement
    {
        $this->guardEnabled();
        $note = $this->note($note);
        $suggested = $suggested === null || $suggested === '' ? null
            : $this->amount($suggested, 'amount_invalid', 'Enter the amount you would like in UGX, between UGX '.number_format(Pricing::MIN_AMOUNT).' and UGX '.number_format(Pricing::MAX_AMOUNT).'.');

        return DB::transaction(function () use ($customer, $requestId, $agreementId, $note, $suggested) {
            $request = $this->lockForCustomer($customer, $requestId);
            $a = $this->find($request, $agreementId);

            if ($a->status === JobPriceAgreement::REVISION_REQUESTED && (int) $a->responded_by_user_id === (int) $customer->getKey()) {
                return $a;
            }
            $this->guardCanDecide($a);
            if ($a->status !== JobPriceAgreement::PENDING_CUSTOMER) {
                throw new BusinessRuleException('already_decided', 'You already asked for a change to this price.', 409);
            }

            $this->move($a, JobPriceAgreement::REVISION_REQUESTED, [
                'responded_by_user_id' => $customer->getKey(), 'responded_at' => now(), 'response_note' => $note, 'suggested_amount_ugx' => $suggested,
            ], 'change_requested', $customer, 'customer', $note);
            $this->notify->priceChangeRequested($a, $request, $customer);

            return $a;
        });
    }

    /* ---------------- When the job itself changes, and time passes ---------------- */

    /**
     * The job was cancelled, declined or finished: a price still being talked about can't be accepted any more.
     * Called inside the caller's transaction, with the request already locked. An agreed price is left as it is.
     */
    public function closeOpenForRequest(ServiceRequest $request, string $reason, ?User $actor = null): void
    {
        $open = JobPriceAgreement::where('service_request_id', $request->id)->whereIn('status', JobPriceAgreement::OPEN_STATUSES)->lockForUpdate()->get();
        foreach ($open as $a) {
            $this->move($a, JobPriceAgreement::CANCELLED, ['closed_reason' => $reason], 'cancelled', $actor, $actor ? ($actor->getKey() === (int) $request->customer_id ? 'customer' : 'provider') : 'system', $reason === 'job_completed' ? 'The job was finished' : 'The job was cancelled');
        }
    }

    /** Proposals nobody answered in time are expired (never agreed). Safe to run any number of times. */
    public function expireDue(int $limit = 500): int
    {
        $requestIds = JobPriceAgreement::where('status', JobPriceAgreement::PENDING_CUSTOMER)
            ->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('service_request_id')->unique();

        $expired = 0;
        foreach ($requestIds as $requestId) {
            $expired += DB::transaction(function () use ($requestId) {
                $request = ServiceRequest::withTrashed()->whereKey($requestId)->lockForUpdate()->first();
                $open = $request ? $this->openFor($request) : null;

                return $open && $this->expireIfDue($open, $request) ? 1 : 0;
            });
        }

        return $expired;
    }

    /* ---------------- What the apps are shown ---------------- */

    /**
     * The price of a request as its two sides see it: the agreed price in force and the version still open.
     * Null while pricing options are switched off. Uses the relations when they are already loaded.
     */
    public static function summary(ServiceRequest $r): ?array
    {
        if (! app(Pricing::class)->enabled()) {
            return null;
        }
        $agreed = $r->relationLoaded('agreedAgreement') ? $r->agreedAgreement : $r->agreedAgreement()->first();
        $open = $r->relationLoaded('openAgreement') ? $r->openAgreement : $r->openAgreement()->first();
        // With nothing agreed or open, the last version says how it ended (declined, expired, withdrawn...)
        $latest = null;
        if (! $agreed && ! $open) {
            $latest = $r->relationLoaded('latestAgreement') ? $r->latestAgreement : $r->latestAgreement()->first();
        }

        return ['agreed' => self::present($agreed), 'open' => self::present($open), 'latest' => self::present($latest)];
    }

    public static function present(?JobPriceAgreement $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'id' => $a->id,
            'revision_no' => $a->revision_no,
            'amount' => $a->amount_ugx,
            'scope' => $a->scope_text,
            'exclusions' => $a->exclusions_text,
            // A proposal that waited too long reads as expired even before the scheduled check has recorded it
            'status' => $a->isDue() ? JobPriceAgreement::EXPIRED : $a->status,
            'is_amendment' => $a->is_amendment,
            'after_inspection' => $a->after_inspection,
            'proposed_at' => $a->proposed_at->toIso8601String(),
            'expires_at' => $a->status === JobPriceAgreement::PENDING_CUSTOMER && $a->expires_at ? $a->expires_at->toIso8601String() : null,
            'responded_at' => optional($a->responded_at)->toIso8601String(),
            'response_note' => $a->response_note,
            'suggested_amount' => $a->suggested_amount_ugx,
        ];
    }

    /**
     * Every version and every step, oldest first, for the two sides of the request and for administrators.
     * Expired proposals are brought up to date first, so what is shown is what is true.
     */
    public function history(ServiceRequest $request): array
    {
        // Brings this request's own overdue proposal up to date first, so what is shown is what is true
        DB::transaction(function () use ($request) {
            $locked = ServiceRequest::withTrashed()->whereKey($request->id)->lockForUpdate()->first();
            $open = $locked ? $this->openFor($locked) : null;
            if ($open) {
                $this->expireIfDue($open, $locked);
            }
        });
        $versions = JobPriceAgreement::where('service_request_id', $request->id)->orderBy('revision_no')->get();
        $revisions = $versions->pluck('revision_no', 'id');

        return [
            'versions' => $versions->map(function (JobPriceAgreement $a) {
                return self::present($a) + ['closed_reason' => $a->closed_reason];
            })->values()->all(),
            'events' => JobPricingEvent::where('service_request_id', $request->id)->orderBy('id')->get()->map(function (JobPricingEvent $e) use ($revisions) {
                return [
                    'event' => $e->event, 'by' => $e->actor_role, 'revision_no' => $e->agreement_id ? ($revisions[$e->agreement_id] ?? null) : null,
                    'from' => $e->from_status, 'to' => $e->to_status, 'amount' => $e->amount_ugx, 'note' => $e->note, 'at' => $e->created_at->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    /* ---------------- Internals ---------------- */

    private function guardEnabled(): void
    {
        if (! $this->pricing->enabled()) {
            throw new BusinessRuleException('pricing_unavailable', 'This isn’t available right now.', 409);
        }
    }

    /** A request this provider business received, locked, for someone who may speak for it on prices */
    private function lockForProvider(User $user, int $requestId): ServiceRequest
    {
        $request = ServiceRequest::receivedBy($user)->whereKey($requestId)->lockForUpdate()->first();
        if (! $request) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $provider = $request->provider;
        if (! $provider || $provider->status !== ProviderProfile::STATUS_APPROVED) {
            throw new BusinessRuleException('provider_not_active', 'Your provider account isn’t active, so you can’t answer requests.', 403);
        }
        if (! $provider->canManageFinances($user)) {
            throw new BusinessRuleException('not_authorised', 'Only the business owner or a manager can do this.', 403);
        }
        $this->guardNotLegacy($request);

        return $request;
    }

    /** The customer's own request, locked; anyone else's is "not found" */
    private function lockForCustomer(User $customer, int $requestId): ServiceRequest
    {
        $request = ServiceRequest::where('customer_id', $customer->getKey())->whereKey($requestId)->lockForUpdate()->first();
        if (! $request) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $this->guardNotLegacy($request);

        return $request;
    }

    private function guardNotLegacy(ServiceRequest $request): void
    {
        if ($request->isLegacy()) {
            throw new BusinessRuleException('legacy_request', 'This request was made before bookings existed, so it keeps its original steps.', 409);
        }
    }

    /** One of this request's versions, locked; one that belongs to another request is "not found" */
    private function find(ServiceRequest $request, int $agreementId): JobPriceAgreement
    {
        $a = JobPriceAgreement::where('service_request_id', $request->id)->whereKey($agreementId)->lockForUpdate()->first();
        if (! $a) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }

        return $a;
    }

    private function openFor(ServiceRequest $request): ?JobPriceAgreement
    {
        return JobPriceAgreement::where('service_request_id', $request->id)->where('open_slot', 1)->lockForUpdate()->first();
    }

    /** The customer may only answer a version that is still open and still the latest */
    private function guardCanDecide(JobPriceAgreement $a): void
    {
        if ($this->expireIfDue($a)) {
            throw new BusinessRuleException('proposal_expired', 'This price offer has expired. Ask the provider for a new one.', 409);
        }
        if ($a->status === JobPriceAgreement::SUPERSEDED) {
            throw new BusinessRuleException('stale_proposal', 'The price has changed. Please review the latest price.', 409);
        }
        if ($a->status === JobPriceAgreement::EXPIRED) {
            throw new BusinessRuleException('proposal_expired', 'This price offer has expired. Ask the provider for a new one.', 409);
        }
        if (! $a->isOpen()) {
            throw new BusinessRuleException('already_decided', 'This price offer has already been answered or closed.', 409);
        }
    }

    /** Marks a proposal that waited too long as expired (and tells the provider once). Returns whether it did. */
    private function expireIfDue(JobPriceAgreement $a, ?ServiceRequest $request = null): bool
    {
        if (! $a->isDue()) {
            return false;
        }
        $request = $request ?: ServiceRequest::withTrashed()->find($a->service_request_id);
        $this->move($a, JobPriceAgreement::EXPIRED, ['closed_reason' => 'timed_out'], 'expired', null, 'system', 'No answer in time');
        if ($request) {
            $this->notify->priceExpired($a, $request);
        }

        return true;
    }

    /**
     * Moves a version to a new status, keeping the two slots right, and writes the event and audit entry.
     * Only status, the slots and the decision fields can change; the model refuses anything else.
     */
    private function move(JobPriceAgreement $a, string $to, array $fields, string $event, ?User $actor, string $role, ?string $note): void
    {
        $from = $a->status;
        $a->forceFill($fields + [
            'status' => $to,
            'open_slot' => in_array($to, JobPriceAgreement::OPEN_STATUSES, true) ? 1 : null,
            'agreed_slot' => $to === JobPriceAgreement::AGREED ? 1 : null,
        ])->save();

        $request = $a->request;
        $this->record($request, $a, $event, $actor, $role, $from, $to, $a->amount_ugx, $note);
        $this->audit->record('marketplace.price_'.$event, $a, ['status' => $from], ['status' => $to, 'revision' => $a->revision_no, 'amount_ugx' => $a->amount_ugx], $note, $actor);
    }

    private function record(ServiceRequest $request, ?JobPriceAgreement $a, string $event, ?User $actor, string $role, ?string $from, ?string $to, ?int $amount, ?string $note): void
    {
        $row = new JobPricingEvent();
        $row->forceFill([
            'service_request_id' => $request->id,
            'agreement_id' => $a ? $a->id : null,
            'event' => $event,
            'actor_id' => $actor ? $actor->getKey() : null,
            'actor_role' => $role,
            'from_status' => $from,
            'to_status' => $to,
            'amount_ugx' => $amount,
            'note' => $note ? mb_substr($note, 0, self::MAX_NOTE) : null,
            'created_at' => now(),
        ])->save();
    }

    private function same(JobPriceAgreement $a, int $amount, string $scope, ?string $exclusions): bool
    {
        return (int) $a->amount_ugx === $amount && $a->scope_text === $scope && (string) $a->exclusions_text === (string) $exclusions;
    }

    /**
     * What the job was, for later financial phases (recorded, never used here): its category and place, and the
     * profession. The profession is derived, never chosen: from the application the provider was hired on, else
     * from the provider's professions in the job's category (the only one, or their main one, flagged for review
     * when several fit, and empty when none does).
     *
     * @return array{profession_id: ?int, source: string, review_needed: bool}
     */
    private function classify(ServiceRequest $request, ProviderProfile $provider): array
    {
        $hiredAs = Quotation::where('service_request_id', $request->id)->where('provider_profile_id', $provider->id)
            ->where('status', Quotation::STATUS_ACCEPTED)->value('profession_id');
        if ($hiredAs) {
            return ['profession_id' => (int) $hiredAs, 'source' => 'quotation', 'review_needed' => false];
        }

        $categoryId = $request->category_id;
        $fromService = false;
        if ($request->provider_service_id) {
            $service = $provider->services()->whereKey($request->provider_service_id)->first();
            if ($service && $service->category_id) {
                $categoryId = $service->category_id;
                $fromService = true;
            }
        }
        $held = $provider->professions()->where('professions.category_id', $categoryId)->get();
        if ($held->count() === 1) {
            return ['profession_id' => (int) $held->first()->getKey(), 'source' => $fromService ? 'service' : 'sole_match', 'review_needed' => false];
        }
        $primary = $held->first(function ($p) {
            return (bool) $p->pivot->is_primary;
        });
        if ($held->count() > 1 && $primary) {
            return ['profession_id' => (int) $primary->getKey(), 'source' => 'primary_in_category', 'review_needed' => true];
        }

        return ['profession_id' => null, 'source' => 'unresolved', 'review_needed' => true];
    }

    private function expiryDays(): int
    {
        return max(1, (int) $this->settings->get('finance.agreement_expiry_days'));
    }

    private function amount($value, string $code, string $message): int
    {
        // Whole UGX only: digits, no fractions, no signs, within the limits every other amount has
        if ($value === null || is_bool($value) || is_float($value) || ! ctype_digit((string) $value)
            || (int) $value < Pricing::MIN_AMOUNT || (int) $value > Pricing::MAX_AMOUNT) {
            throw new BusinessRuleException($code, $message, 422);
        }

        return (int) $value;
    }

    /** Trimmed, with runs of spaces collapsed and blank lines limited; line breaks are kept */
    private function text($value, int $max): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);

        return mb_substr(trim($value), 0, $max);
    }

    private function note(?string $note): ?string
    {
        $note = $this->text($note ?? '', self::MAX_NOTE);

        return $note === '' ? null : $note;
    }
}
