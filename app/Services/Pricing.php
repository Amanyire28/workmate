<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase B1: what people SAY about price, before any price is agreed. Customers describe a budget
 * (fixed, range, negotiable or none), providers give an estimate when they apply (fixed, range or
 * "I must inspect first") and can mark a job as needing inspection.
 *
 * Nothing here is a price of a job, a payment, a commission or a debt: a budget and an estimate are
 * guides that the customer and provider talk about. All of it sits behind the platform setting
 * finance.pricing_enabled (off by default); while it is off the new fields are not accepted, so the
 * marketplace behaves exactly as before. Amounts are whole UGX, never fractions.
 */
class Pricing
{
    /** The same limits the older budget and price fields have always had */
    public const MIN_AMOUNT = 1000;
    public const MAX_AMOUNT = 100000000;

    /** What a budget is "per", for budgets that aren't a single total */
    private const BASES = ['fixed', 'total', 'per person', 'per day', 'per hour'];

    /** @var PlatformSettings */
    private $settings;

    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    public function __construct(PlatformSettings $settings, AuditLogger $audit, EventNotifications $notify)
    {
        $this->settings = $settings;
        $this->audit = $audit;
        $this->notify = $notify;
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('finance.pricing_enabled');
    }

    /* ---------------- Validation rules (only while switched on) ---------------- */

    /**
     * Extra rules for a request or job that takes a budget type. Empty while pricing is off, so the new
     * fields are simply not part of what is read from the request.
     */
    public function budgetRules(): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $amount = ['integer', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_AMOUNT];

        return [
            'budget_type' => ['nullable', 'in:'.implode(',', ServiceRequest::BUDGET_TYPES)],
            // A fixed budget needs its amount; a negotiable one may carry a starting guide
            'budget' => array_merge(['nullable'], $amount, ['required_if:budget_type,fixed']),
            'budget_min' => array_merge(['nullable'], $amount, ['required_if:budget_type,range']),
            'budget_max' => array_merge(['nullable'], $amount, ['required_if:budget_type,range', 'gt:budget_min']),
        ];
    }

    public function budgetMessages(): array
    {
        return [
            'budget_type.in' => 'Choose how you describe your budget.',
            'budget.required_if' => 'Enter your budget, or choose another option.',
            'budget_min.required_if' => 'Enter the lowest amount you would pay.',
            'budget_max.required_if' => 'Enter the highest amount you would pay.',
            'budget_max.gt' => 'The highest amount must be more than the lowest.',
            'budget_min.min' => 'Enter an amount of at least UGX 1,000.',
            'budget_max.min' => 'Enter an amount of at least UGX 1,000.',
        ];
    }

    /** Extra rules for an application that carries an estimate and the profession applied as */
    public function estimateRules(): array
    {
        if (! $this->enabled()) {
            return [];
        }
        $amount = ['integer', 'min:'.self::MIN_AMOUNT, 'max:'.self::MAX_AMOUNT];

        return [
            'estimate_type' => ['nullable', 'in:'.implode(',', Quotation::ESTIMATE_TYPES)],
            'amount' => array_merge(['nullable'], $amount, ['required_if:estimate_type,fixed,range']),
            'amount_max' => array_merge(['nullable'], $amount, ['required_if:estimate_type,range', 'gt:amount']),
            'profession' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function estimateMessages(): array
    {
        return [
            'estimate_type.in' => 'Choose what kind of estimate you are giving.',
            'amount.required_if' => 'Enter your price, or choose “I need to see the job first”.',
            'amount_max.required_if' => 'Enter the highest amount of your range.',
            'amount_max.gt' => 'The highest amount must be more than the lowest.',
        ];
    }

    /* ---------------- Budgets ---------------- */

    /**
     * The budget columns for a new request. Without a budget type (an older app, or pricing switched off)
     * it is the original behaviour: an amount with its basis, or nothing. The older budget and
     * budget_basis columns are always still written, because older apps read them.
     *
     * @param  array  $data  validated input
     * @return array{budget: ?int, budget_basis: ?string, budget_type: ?string, budget_min: ?int, budget_max: ?int}
     */
    public function budgetColumns(array $data): array
    {
        $amount = isset($data['budget']) ? (int) $data['budget'] : null;
        $basis = $data['budget_basis'] ?? null;
        $type = $this->enabled() ? ($data['budget_type'] ?? null) : null;

        if ($type === null) {
            return [
                'budget' => $amount,
                'budget_basis' => $amount !== null ? ($basis ?: 'fixed') : null,
                // Stored only once budget types are on, so nothing changes for requests made before
                'budget_type' => $this->enabled() ? $this->legacyType($amount, $basis) : null,
                'budget_min' => null,
                'budget_max' => null,
            ];
        }

        switch ($type) {
            case ServiceRequest::BUDGET_FIXED:
                if ($amount === null) {
                    throw new BusinessRuleException('budget_required', 'Enter your budget, or choose another option.', 422);
                }

                return ['budget' => $amount, 'budget_basis' => $this->basis($basis, 'fixed'), 'budget_type' => $type, 'budget_min' => null, 'budget_max' => null];

            case ServiceRequest::BUDGET_RANGE:
                $min = isset($data['budget_min']) ? (int) $data['budget_min'] : null;
                $max = isset($data['budget_max']) ? (int) $data['budget_max'] : null;
                if ($min === null || $max === null || $min < self::MIN_AMOUNT || $max <= $min) {
                    throw new BusinessRuleException('budget_range_invalid', 'Enter the lowest amount and a higher highest amount.', 422);
                }

                // No single amount: older apps show "no budget" for a range
                return ['budget' => null, 'budget_basis' => $this->basis($basis, 'total'), 'budget_type' => $type, 'budget_min' => $min, 'budget_max' => $max];

            case ServiceRequest::BUDGET_NEGOTIABLE:
                // An amount, if given, is only where the talking starts
                return ['budget' => $amount, 'budget_basis' => 'negotiable', 'budget_type' => $type, 'budget_min' => null, 'budget_max' => null];

            default:
                return ['budget' => null, 'budget_basis' => null, 'budget_type' => ServiceRequest::BUDGET_NONE, 'budget_min' => null, 'budget_max' => null];
        }
    }

    private function legacyType(?int $amount, ?string $basis): string
    {
        if ($amount === null) {
            return ServiceRequest::BUDGET_NONE;
        }

        return $basis === 'negotiable' ? ServiceRequest::BUDGET_NEGOTIABLE : ServiceRequest::BUDGET_FIXED;
    }

    private function basis(?string $basis, string $default): string
    {
        return in_array($basis, self::BASES, true) ? $basis : $default;
    }

    /* ---------------- Estimates and the profession applied as ---------------- */

    /**
     * The price columns of an application. Without an estimate type it is the original behaviour: an
     * optional amount. An estimate is a guide for the customer; the price of the job is agreed later.
     *
     * @param  array  $data  validated input
     * @return array{amount: ?int, amount_max: ?int, estimate_type: ?string}
     */
    public function estimateColumns(array $data): array
    {
        $amount = isset($data['amount']) ? (int) $data['amount'] : null;
        if (! $this->enabled()) {
            return ['amount' => $amount, 'amount_max' => null, 'estimate_type' => null];
        }

        $type = $data['estimate_type'] ?? null;
        switch ($type) {
            case Quotation::ESTIMATE_INSPECTION:
                // "I must see it first": no figure at all, whatever was sent
                return ['amount' => null, 'amount_max' => null, 'estimate_type' => $type];

            case Quotation::ESTIMATE_RANGE:
                $max = isset($data['amount_max']) ? (int) $data['amount_max'] : null;
                if ($amount === null || $max === null || $amount < self::MIN_AMOUNT || $max <= $amount) {
                    throw new BusinessRuleException('estimate_range_invalid', 'Enter the lowest amount and a higher highest amount.', 422);
                }

                return ['amount' => $amount, 'amount_max' => $max, 'estimate_type' => $type];

            case Quotation::ESTIMATE_FIXED:
                if ($amount === null) {
                    throw new BusinessRuleException('estimate_required', 'Enter your price, or choose “I need to see the job first”.', 422);
                }

                return ['amount' => $amount, 'amount_max' => null, 'estimate_type' => $type];

            default:
                // An older app: the amount is a price offer, as it has always been
                return ['amount' => $amount, 'amount_max' => null, 'estimate_type' => $amount !== null ? Quotation::ESTIMATE_FIXED : null];
        }
    }

    /**
     * The provider's professions that fit a job (those in its category), with their default.
     *
     * @return array{professions: array, default: ?string, must_choose: bool}
     */
    public function professionOptions(ProviderProfile $provider, ServiceRequest $job): array
    {
        $held = $provider->professions()->where('professions.category_id', $job->category_id)->orderBy('professions.name')->get();
        $primary = $held->first(function ($p) {
            return (bool) $p->pivot->is_primary;
        });
        $default = $primary ?: ($held->count() === 1 ? $held->first() : null);

        return [
            'professions' => $held->map(function ($p) {
                return ['slug' => $p->slug, 'name' => $p->name, 'is_primary' => (bool) $p->pivot->is_primary];
            })->values()->all(),
            'default' => $default ? $default->slug : null,
            // Several fit and none is their main one: the app asks, rather than guess
            'must_choose' => $held->count() > 1 && ! $primary,
        ];
    }

    /**
     * The profession an application is made as. A chosen one must be the provider's own and fit the
     * job's category, so nobody can pick a profession they don't hold. Without a choice (an older app) it
     * is their only fitting one or their main one, and otherwise it is left empty rather than guessed.
     */
    public function professionFor(ProviderProfile $provider, ServiceRequest $job, ?string $slug): ?int
    {
        if (! $this->enabled()) {
            return null;
        }

        $held = $provider->professions()->where('professions.category_id', $job->category_id)->get();

        if ($slug !== null && $slug !== '') {
            $chosen = $held->firstWhere('slug', $slug);
            if (! $chosen) {
                throw new BusinessRuleException('profession_invalid', 'Choose one of your own skills that fits this job.', 422);
            }

            return (int) $chosen->getKey();
        }

        $primary = $held->first(function ($p) {
            return (bool) $p->pivot->is_primary;
        });
        if ($primary) {
            return (int) $primary->getKey();
        }

        return $held->count() === 1 ? (int) $held->first()->getKey() : null;
    }

    /* ---------------- Inspection ---------------- */

    /**
     * The provider says they must see the job before they can give a price (or takes that back). Only the
     * provider business's owner or a manager can, only for a request sent to it or a job it was hired for,
     * and only while the job is still to be done. It records a flag and a note; nothing is priced.
     */
    public function setInspection(User $user, ServiceRequest $request, bool $required, ?string $note): ServiceRequest
    {
        if (! $this->enabled()) {
            throw new BusinessRuleException('pricing_unavailable', 'This isn’t available right now.', 409);
        }

        $note = $note === null ? null : mb_substr(trim(preg_replace('/\s+/u', ' ', $note)), 0, 300);
        $note = $note === '' ? null : $note;

        return DB::transaction(function () use ($user, $request, $required, $note) {
            $locked = ServiceRequest::receivedBy($user)->whereKey($request->getKey())->lockForUpdate()->first();
            if (! $locked) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            $provider = $locked->provider;
            if (! $provider || $provider->status !== ProviderProfile::STATUS_APPROVED) {
                throw new BusinessRuleException('provider_not_active', 'Your provider account isn’t active, so you can’t answer requests.', 403);
            }
            if (! $provider->canManageFinances($user)) {
                throw new BusinessRuleException('not_authorised', 'Only the business owner or a manager can do this.', 403);
            }
            if ($locked->isLegacy()) {
                throw new BusinessRuleException('legacy_request', 'This request was made before bookings existed, so it keeps its original steps.', 409);
            }
            if (! in_array($locked->status, [ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_SCHEDULED, ServiceRequest::STATUS_IN_PROGRESS], true)) {
                throw new BusinessRuleException('request_status_conflict', 'Inspection can only be set on a job that’s still to be done.', 409);
            }

            $was = $locked->needsInspection();
            $newNote = $required ? $note : null;
            // The same thing again (a double tap, a retry) changes nothing and records nothing
            if ($required === $was && $newNote === $locked->inspection_note) {
                return $locked;
            }

            $before = ['inspection_required' => $was, 'note' => $locked->inspection_note];
            $locked->forceFill([
                'inspection_required_at' => $required ? ($locked->inspection_required_at ?: now()) : null,
                'inspection_note' => $newNote,
            ])->save();

            $this->audit->record($required ? 'marketplace.inspection_required' : 'marketplace.inspection_cleared', $locked, $before, ['inspection_required' => $required, 'note' => $newNote], null, $user);
            if ($required && ! $was) {
                $this->notify->inspectionRequired($locked);
            }

            return $locked;
        });
    }

    /* ---------------- What the apps are shown ---------------- */

    /** The budget as every viewer of a request sees it (additive: the older fields are still sent) */
    public static function budgetFields(ServiceRequest $r): array
    {
        return [
            'budget_type' => $r->effectiveBudgetType(),
            'budget_min' => $r->budget_min,
            'budget_max' => $r->budget_max,
        ];
    }

    /** Inspection, for the customer who sent the request and the provider business that has it */
    public static function inspectionFields(ServiceRequest $r): array
    {
        return [
            'inspection_required' => $r->needsInspection(),
            'inspection_note' => $r->needsInspection() ? $r->inspection_note : null,
        ];
    }
}
