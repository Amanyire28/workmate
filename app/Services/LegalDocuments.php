<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Legal documents, what each person has accepted, and optional consents.
 *
 * Bodies are Markdown. {{fact}} placeholders are filled from the legal
 * settings (Admin → Legal Documents → Company facts) when shown; [[note]] is a
 * drafting note, highlighted until an administrator removes it.
 *
 * A person must accept the latest material version of each document for
 * their audience; minor versions don't ask again.
 */
class LegalDocuments
{
    /** Placeholder => setting key, or null for values worked out here */
    public const FACTS = [
        'registration_number' => 'legal.registration_number',
        'registered_office' => 'legal.registered_office',
        'directors' => 'legal.directors',
        'service_address' => 'legal.service_address',
        'pdpo_certificate' => 'legal.pdpo_certificate',
        'dpo_name' => 'legal.dpo_name',
        'privacy_email' => 'legal.privacy_email',
        'support_email' => 'platform.support_email',
        'support_phone' => 'platform.support_phone',
        'support_hours' => 'legal.support_hours',
        'hosting_provider' => 'legal.hosting_provider',
        'email_provider' => 'legal.email_provider',
        'pdpo_contact' => 'legal.pdpo_contact',
        'liability_cap' => 'legal.liability_cap',
        'minimum_age' => 'legal.minimum_age',
        'legal_url' => null,
    ];

    /** @var AuditLogger */
    private $audit;

    /** @var PlatformSettings */
    private $settings;

    public function __construct(AuditLogger $audit, PlatformSettings $settings)
    {
        $this->audit = $audit;
        $this->settings = $settings;
    }

    /** @return Collection<int, LegalDocument> */
    public function documents(): Collection
    {
        return LegalDocument::orderBy('sort_order')->orderBy('id')->get();
    }

    public function find(string $key): LegalDocument
    {
        $document = LegalDocument::where('key', $key)->first();
        if (! $document) {
            throw new BusinessRuleException('legal_document_not_found', 'That document doesn’t exist.', 404);
        }

        return $document;
    }

    public function current(LegalDocument $document): ?LegalDocumentVersion
    {
        return $document->publishedVersions()->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    /** The version people must have accepted (or a later one): the latest material one */
    public function latestMaterial(LegalDocument $document): ?LegalDocumentVersion
    {
        return $document->publishedVersions()->where('is_material', true)->orderByDesc('published_at')->orderByDesc('id')->first();
    }

    /* ---------------- Showing documents ---------------- */

    /** @return array<string, ?string> placeholder => value (null when not set yet) */
    public function facts(): array
    {
        $facts = [];
        foreach (self::FACTS as $name => $setting) {
            $value = $setting ? $this->settings->get($setting) : url('/legal');
            $facts[$name] = $value === null || $value === '' ? null : (string) $value;
        }

        return $facts;
    }

    public function render(LegalDocumentVersion $version): string
    {
        $converter = new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $html = (string) $converter->convert($version->body);
        $facts = $this->facts();

        $html = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($facts) {
            $name = $m[1];
            if (! array_key_exists($name, $facts)) {
                return e($m[0]);
            }
            if ($facts[$name] === null) {
                $label = Str::ucfirst(str_replace('_', ' ', $name));

                return '<mark class="wm-legal-missing" title="Set this in Admin → Legal Documents → Company facts">['.e($label).': to be confirmed]</mark>';
            }

            return e($facts[$name]);
        }, $html);

        // Drafting notes; the text inside was already escaped by the converter
        return preg_replace('/\[\[(.+?)\]\]/s', '<mark class="wm-legal-note" title="Drafting note">$1</mark>', $html);
    }

    /** What the app shows about one version */
    public function present(LegalDocument $document, LegalDocumentVersion $version, bool $withBody = true): array
    {
        $data = [
            'key' => $document->key,
            'title' => $document->title,
            'audience' => $document->audience,
            'version_id' => $version->id,
            'version' => $version->version,
            'status' => $version->status,
            'summary' => $version->summary ?: $document->summary,
            'change_note' => $version->change_note,
            'is_material' => $version->is_material,
            'published_at' => optional($version->published_at)->toIso8601String(),
        ];
        if ($withBody) {
            $data['html'] = $this->render($version);
        }

        return $data;
    }

    /* ---------------- Acceptance ---------------- */

    public function askingForAcceptance(): bool
    {
        return (bool) $this->settings->get('legal.ask_acceptance');
    }

    /**
     * Documents this person is bound by: everyone's, and providers' once they've
     * sent an application. While it's still a draft they agree to it when sending
     * (Start earning), so they aren't stopped half-way through.
     */
    public function documentsFor(User $user): Collection
    {
        $isProvider = $user->providerProfile()->where('status', '!=', ProviderProfile::STATUS_DRAFT)->exists();

        return $this->documents()->filter(function (LegalDocument $d) use ($isProvider) {
            return $d->audience === LegalDocument::AUDIENCE_EVERYONE || ($isProvider && $d->audience === LegalDocument::AUDIENCE_PROVIDERS);
        })->values();
    }

    /**
     * Documents this person still needs to accept, with the current version to accept.
     *
     * @return array<int, array>
     */
    public function pendingFor(User $user): array
    {
        if (! $this->askingForAcceptance()) {
            return [];
        }

        $accepted = LegalAcceptance::where('user_id', $user->id)
            ->join('legal_document_versions', 'legal_document_versions.id', '=', 'legal_acceptances.legal_document_version_id')
            ->get(['legal_document_versions.legal_document_id', 'legal_document_versions.published_at'])
            ->groupBy('legal_document_id');

        $pending = [];
        foreach ($this->documentsFor($user) as $document) {
            $material = $this->latestMaterial($document);
            $current = $this->current($document);
            if (! $material || ! $current) {
                continue;
            }
            $mine = $accepted->get($document->id, collect());
            $upToDate = $mine->contains(function ($row) use ($material) {
                return $row->published_at && Carbon::parse($row->published_at)->gte($material->published_at);
            });
            if (! $upToDate) {
                $item = $this->present($document, $current, false);
                // Accepted an earlier version: this is an update, so show what changed
                $item['is_update'] = $mine->isNotEmpty();
                $pending[] = $item;
            }
        }

        return $pending;
    }

    /**
     * Records acceptance of these versions. Each must be the current published
     * version of a document this person is bound by (or, when applying, the
     * providers' agreement).
     *
     * @param  int[]  $versionIds
     */
    public function accept(User $user, array $versionIds, string $method, ?Request $request = null): void
    {
        $versionIds = array_values(array_unique(array_map('intval', $versionIds)));
        $allowed = $this->documents()->filter(function (LegalDocument $d) use ($user, $method) {
            return $d->audience === LegalDocument::AUDIENCE_EVERYONE
                || $method === LegalAcceptance::METHOD_PROVIDER_APPLICATION
                || $user->providerProfile()->exists();
        })->mapWithKeys(function (LegalDocument $d) {
            $current = $this->current($d);

            return $current ? [$current->id => $d] : [];
        });

        foreach ($versionIds as $id) {
            if (! $allowed->has($id)) {
                throw new BusinessRuleException('legal_version_not_current', 'That version isn’t current any more. Reload to see the latest documents.', 422);
            }
        }

        DB::transaction(function () use ($user, $versionIds, $method, $request) {
            $now = now();
            foreach ($versionIds as $id) {
                $created = DB::table('legal_acceptances')->insertOrIgnore([
                    'user_id' => $user->id,
                    'legal_document_version_id' => $id,
                    'method' => $method,
                    'ip_address' => $request ? $request->ip() : null,
                    'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                    'accepted_at' => $now,
                ]);
                if ($created) {
                    $version = LegalDocumentVersion::with('document')->find($id);
                    $this->audit->record('legal.accepted', $version, [], [
                        'document' => $version->document->key, 'version' => $version->version, 'method' => $method,
                    ], null, $user);
                }
            }
        });
    }

    /** At sign-up: every document for everyone, at its current version */
    public function acceptAtSignUp(User $user, Request $request): void
    {
        $ids = $this->documents()->where('audience', LegalDocument::AUDIENCE_EVERYONE)->map(function (LegalDocument $d) {
            return optional($this->current($d))->id;
        })->filter()->values()->all();

        $user->forceFill(['adult_confirmed_at' => now()])->save();
        $this->accept($user, $ids, LegalAcceptance::METHOD_SIGN_UP, $request);
    }

    /** When applying to be a provider: the providers' agreement, if one is published */
    public function providerAgreement(): ?LegalDocumentVersion
    {
        $document = $this->documents()->firstWhere('audience', LegalDocument::AUDIENCE_PROVIDERS);

        return $document ? $this->current($document) : null;
    }

    /** Everything this person accepted, newest first */
    public function historyFor(User $user): array
    {
        return LegalAcceptance::where('user_id', $user->id)
            ->with('version.document')
            ->orderByDesc('accepted_at')->orderByDesc('id')
            ->get()
            ->map(function (LegalAcceptance $a) {
                return [
                    'key' => $a->version->document->key,
                    'title' => $a->version->document->title,
                    'version' => $a->version->version,
                    'version_id' => $a->version->id,
                    'method' => $a->method,
                    'accepted_at' => $a->accepted_at->toIso8601String(),
                ];
            })->all();
    }

    /* ---------------- Optional consents ---------------- */

    /** @return array<string, bool> */
    public function consentsFor(User $user): array
    {
        $out = [];
        foreach (UserConsent::PURPOSES as $purpose) {
            $latest = UserConsent::where('user_id', $user->id)->where('purpose', $purpose)->orderByDesc('id')->first();
            $out[$purpose] = $latest ? $latest->granted : false;
        }

        return $out;
    }

    public function setConsent(User $user, string $purpose, bool $granted, string $source, ?Request $request = null): void
    {
        if (! in_array($purpose, UserConsent::PURPOSES, true)) {
            throw new BusinessRuleException('unknown_consent', 'That choice doesn’t exist.', 422);
        }
        // Nothing to record when it doesn't change, except the first choice at sign-up
        if ($source !== 'sign_up' && ($this->consentsFor($user)[$purpose] ?? false) === $granted) {
            return;
        }

        DB::transaction(function () use ($user, $purpose, $granted, $source, $request) {
            $consent = new UserConsent();
            $consent->forceFill([
                'user_id' => $user->id,
                'purpose' => $purpose,
                'granted' => $granted,
                'source' => $source,
                'ip_address' => $request ? $request->ip() : null,
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
                'created_at' => now(),
            ])->save();
            $this->audit->record($granted ? 'consent.given' : 'consent.withdrawn', $user, [], ['purpose' => $purpose, 'source' => $source], null, $user);
        });
    }

    /* ---------------- Administration ---------------- */

    /** Every document with all versions and how many people accepted each */
    public function adminOverview(): array
    {
        $counts = LegalAcceptance::query()
            ->selectRaw('legal_document_version_id, count(*) as total')
            ->groupBy('legal_document_version_id')
            ->pluck('total', 'legal_document_version_id');
        $activeUsers = User::where('status', User::STATUS_ACTIVE)->count();

        return $this->documents()->map(function (LegalDocument $d) use ($counts, $activeUsers) {
            $versions = $d->versions()->with('publisher:id,name')->orderByDesc('id')->get();
            $material = $this->latestMaterial($d);
            // People who accepted the latest material version or anything after it
            $upToDate = $material ? LegalAcceptance::query()
                ->join('legal_document_versions', 'legal_document_versions.id', '=', 'legal_acceptances.legal_document_version_id')
                ->where('legal_document_versions.legal_document_id', $d->id)
                ->where('legal_document_versions.published_at', '>=', $material->published_at)
                ->distinct()->count('legal_acceptances.user_id') : 0;

            return [
                'key' => $d->key,
                'title' => $d->title,
                'audience' => $d->audience,
                'up_to_date' => $upToDate,
                'active_users' => $activeUsers,
                'versions' => $versions->map(function (LegalDocumentVersion $v) use ($counts) {
                    return [
                        'id' => $v->id,
                        'version' => $v->version,
                        'status' => $v->status,
                        'summary' => $v->summary,
                        'body' => $v->body,
                        'change_note' => $v->change_note,
                        'is_material' => $v->is_material,
                        'published_at' => optional($v->published_at)->toIso8601String(),
                        'published_by' => optional($v->publisher)->name,
                        'updated_at' => optional($v->updated_at)->toIso8601String(),
                        'acceptances' => (int) ($counts[$v->id] ?? 0),
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }

    /** A new draft, starting from the current text. One draft per document at a time. */
    public function createDraft(User $actor, LegalDocument $document): LegalDocumentVersion
    {
        if ($document->versions()->where('status', LegalDocumentVersion::STATUS_DRAFT)->exists()) {
            throw new BusinessRuleException('legal_draft_exists', 'This document already has a draft. Edit or discard it first.', 409);
        }
        $current = $this->current($document);

        return DB::transaction(function () use ($actor, $document, $current) {
            $draft = new LegalDocumentVersion();
            $draft->forceFill([
                'legal_document_id' => $document->id,
                'version' => $this->nextVersion($document),
                'status' => LegalDocumentVersion::STATUS_DRAFT,
                'summary' => $current ? $current->summary : $document->summary,
                'body' => $current ? $current->body : '',
                'is_material' => true,
                'created_by' => $actor->id,
            ])->save();
            $this->audit->record('legal.draft_created', $draft, [], ['document' => $document->key, 'version' => $draft->version], null, $actor);

            return $draft;
        });
    }

    /** "0.1" → "0.2"; anything else gets ".1" added */
    private function nextVersion(LegalDocument $document): string
    {
        $taken = $document->versions()->pluck('version')->all();
        $current = $this->current($document);
        $base = $current ? $current->version : '0';
        if (preg_match('/^(\d+)\.(\d+)$/', $base, $m)) {
            $n = (int) $m[2];
            do {
                $next = $m[1].'.'.(++$n);
            } while (in_array($next, $taken, true));

            return $next;
        }

        return $base.'.1';
    }

    /** @param array{version?: string, summary?: ?string, body?: string, change_note?: ?string, is_material?: bool} $data */
    public function updateDraft(User $actor, LegalDocumentVersion $draft, array $data): LegalDocumentVersion
    {
        $this->assertDraft($draft);
        if (isset($data['version']) && $draft->document->versions()->where('id', '!=', $draft->id)->where('version', $data['version'])->exists()) {
            throw new BusinessRuleException('legal_version_taken', 'That version number is already used for this document.', 422);
        }
        $draft->forceFill($data);
        if ($draft->isDirty()) {
            DB::transaction(function () use ($actor, $draft) {
                $changed = array_keys($draft->getDirty());
                $draft->save();
                // The text itself isn't copied into the audit log; the version keeps it
                $this->audit->record('legal.draft_updated', $draft, [], ['document' => $draft->document->key, 'version' => $draft->version, 'fields' => $changed], null, $actor);
            });
        }

        return $draft;
    }

    public function publish(User $actor, LegalDocumentVersion $draft, string $reason): LegalDocumentVersion
    {
        $this->assertDraft($draft);
        if (trim($draft->body) === '') {
            throw new BusinessRuleException('legal_empty', 'Add the document text before publishing.', 422);
        }
        if (! $draft->change_note) {
            throw new BusinessRuleException('legal_change_note', 'Say what changed before publishing; people see it when they’re asked to accept.', 422);
        }

        return DB::transaction(function () use ($actor, $draft, $reason) {
            $draft->forceFill([
                'status' => LegalDocumentVersion::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $actor->id,
            ])->save();
            $this->audit->record('legal.published', $draft, [], [
                'document' => $draft->document->key, 'version' => $draft->version, 'material' => $draft->is_material,
            ], $reason, $actor);

            return $draft;
        });
    }

    public function discard(User $actor, LegalDocumentVersion $draft): void
    {
        $this->assertDraft($draft);
        DB::transaction(function () use ($actor, $draft) {
            $this->audit->record('legal.draft_discarded', $draft, ['document' => $draft->document->key, 'version' => $draft->version], [], null, $actor);
            $draft->delete();
        });
    }

    private function assertDraft(LegalDocumentVersion $version): void
    {
        if ($version->isPublished()) {
            throw new BusinessRuleException('legal_published', 'Published versions can’t be changed. Start a new draft instead.', 409);
        }
    }
}
