<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\DocumentRequirementVersion;
use App\Models\DocumentVerification;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The documents each profession (category) needs on top of the National ID,
 * which everyone always needs. Administrators publish versions; a provider
 * follows the current version of their main skill's profession until they
 * send their documents, and the version they sent them under from then on.
 */
class DocumentRequirements
{
    public const LEVELS = ['mandatory', 'optional', 'conditional'];

    public const FORMATS = ['jpg', 'png', 'pdf'];

    /** The same limit the server already accepts for every verification file */
    public const MAX_SIZE_MB = 8;

    public const MAX_COUNT = 5;

    public const MAX_DOCUMENTS = 12;

    /**
     * When a conditional document applies. "business" comes from the provider
     * type they chose; the rest are asked on the Get verified page.
     */
    public const CONDITIONS = [
        'own_vehicle' => 'Do you use your own vehicle for this work?',
        'business' => null,
    ];

    /** Kept out of profession lists: handled for everyone */
    private const RESERVED_KEYS = ['national_id', 'supporting'];

    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    public function current(int $categoryId): ?DocumentRequirementVersion
    {
        return DocumentRequirementVersion::where('category_id', $categoryId)->orderByDesc('version')->first();
    }

    /** The version that applies to this provider's documents right now */
    public function versionFor(DocumentVerification $verification): ?DocumentRequirementVersion
    {
        // Not sent yet (or starting again after a rejection): the current rules
        if (in_array($verification->status, [DocumentVerification::STATUS_DRAFT, DocumentVerification::STATUS_REJECTED], true)) {
            $category = $this->mainCategoryId($verification->profile);

            return $category ? $this->current($category) : null;
        }

        return $verification->requirementVersion;
    }

    /** The profession of the provider's first (main) skill */
    public function mainCategoryId(ProviderProfile $profile): ?int
    {
        $professions = $profile->professions()->get();
        $main = $professions->first(function ($p) {
            return (bool) $p->pivot->is_primary;
        }) ?: $professions->first();

        return $main ? (int) $main->category_id : null;
    }

    /** @return array<string, bool|null> */
    public function answers(DocumentVerification $verification): array
    {
        $given = $verification->answers ?: [];

        return [
            'business' => in_array($verification->profile->type, ['group', 'company'], true),
            'own_vehicle' => array_key_exists('own_vehicle', $given) ? (bool) $given['own_vehicle'] : null,
        ];
    }

    /** Enabled documents that apply, given the provider's answers */
    public function applicable(DocumentVerification $verification, ?DocumentRequirementVersion $version = null): array
    {
        $version = $version ?: $this->versionFor($verification);
        if (! $version) {
            return [];
        }
        $answers = $this->answers($verification);

        return array_values(array_filter($version->documents, function ($d) use ($answers) {
            return $d['enabled'] && ($d['requirement'] !== 'conditional' || ! empty($answers[$d['condition']]));
        }));
    }

    /** One document this provider may add, or null */
    public function spec(DocumentVerification $verification, string $key): ?array
    {
        foreach ($this->applicable($verification) as $d) {
            if ($d['id'] === $key) {
                return $d;
            }
        }

        return null;
    }

    public static function isRequired(array $document): bool
    {
        // Conditional documents only reach here when their condition applies
        return in_array($document['requirement'], ['mandatory', 'conditional'], true);
    }

    /** Questions the provider must answer, with their answers so far */
    public function questions(DocumentVerification $verification, ?DocumentRequirementVersion $version = null): array
    {
        $version = $version ?: $this->versionFor($verification);
        if (! $version) {
            return [];
        }
        $answers = $this->answers($verification);
        $used = collect($version->documents)->filter(function ($d) {
            return $d['enabled'] && $d['requirement'] === 'conditional';
        })->pluck('condition')->unique();

        return $used->filter(function ($key) {
            return isset(self::CONDITIONS[$key]);
        })->map(function ($key) use ($answers) {
            return ['key' => $key, 'question' => self::CONDITIONS[$key], 'answer' => $answers[$key]];
        })->values()->all();
    }

    /**
     * Names of required documents still missing.
     *
     * @param  Collection<int, VerificationDocument>  $documents  the current documents
     */
    public function missing(DocumentVerification $verification, Collection $documents, ?DocumentRequirementVersion $version = null): array
    {
        $missing = [];
        foreach ($this->applicable($verification, $version) as $d) {
            $added = $documents->where('kind', VerificationDocument::KIND_PROFESSION)->where('requirement_key', $d['id'])->count();
            if (self::isRequired($d) && $added < $d['count']) {
                $missing[] = $d['name'];
            }
        }

        return $missing;
    }

    /** What the provider sees: the documents to add and the questions to answer */
    public function present(DocumentVerification $verification, Collection $documents): ?array
    {
        $version = $this->versionFor($verification);
        if (! $version) {
            return null;
        }

        return [
            'profession' => optional($version->category)->name,
            'version' => $version->version,
            'questions' => $this->questions($verification, $version),
            'documents' => array_map(function ($d) use ($documents) {
                return [
                    'key' => $d['id'], 'name' => $d['name'], 'instructions' => $d['instructions'] ?? null,
                    'required' => self::isRequired($d), 'count' => $d['count'],
                    'formats' => $d['formats'], 'max_size_mb' => $d['maxSizeMb'],
                    'front_back' => (bool) $d['frontBack'], 'multi_page' => (bool) $d['multiPage'],
                    'expiry_required' => (bool) $d['expiry']['required'], 'min_months_valid' => (int) $d['expiry']['minMonthsValid'],
                    'added' => $documents->where('kind', VerificationDocument::KIND_PROFESSION)->where('requirement_key', $d['id'])->count(),
                ];
            }, $this->applicable($verification, $version)),
            'missing' => $this->missing($verification, $documents, $version),
        ];
    }

    /* ---------------- Administrators ---------------- */

    /** Every profession's versions, newest last, with how many applications used each */
    public function overview(): array
    {
        $used = DocumentVerification::whereNotNull('requirement_version_id')
            ->select('requirement_version_id', DB::raw('count(*) as total'))
            ->groupBy('requirement_version_id')->pluck('total', 'requirement_version_id');

        return DocumentRequirementVersion::with(['category', 'publisher'])->orderBy('version')->get()
            ->groupBy(function ($v) {
                return $v->category->slug;
            })
            ->map(function ($versions) use ($used) {
                return $versions->map(function (DocumentRequirementVersion $v) use ($used) {
                    return $this->presentVersion($v, (int) ($used[$v->id] ?? 0));
                })->values();
            })->all();
    }

    public function presentVersion(DocumentRequirementVersion $v, int $applications = 0): array
    {
        return [
            'version' => $v->version,
            'publishedAt' => $v->published_at->toIso8601String(),
            'publishedBy' => $v->published_by ? optional($v->publisher)->name : 'WorkMate',
            'note' => $v->note,
            'documents' => $v->documents,
            'applications' => $applications,
        ];
    }

    public static function rules(): array
    {
        return [
            'documents' => ['present', 'array', 'max:'.self::MAX_DOCUMENTS],
            'documents.*.id' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', 'distinct', Rule::notIn(self::RESERVED_KEYS)],
            'documents.*.type' => ['nullable', 'string', 'max:40'],
            'documents.*.name' => ['required', 'string', 'min:3', 'max:80'],
            'documents.*.instructions' => ['nullable', 'string', 'max:500'],
            'documents.*.requirement' => ['required', Rule::in(self::LEVELS)],
            'documents.*.condition' => ['nullable', 'required_if:documents.*.requirement,conditional', Rule::in(array_keys(self::CONDITIONS))],
            'documents.*.enabled' => ['required', 'boolean'],
            'documents.*.count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COUNT],
            'documents.*.maxSizeMb' => ['required', 'integer', 'min:1', 'max:'.self::MAX_SIZE_MB],
            'documents.*.formats' => ['required', 'array', 'min:1'],
            // Repeats are dropped when saved
            'documents.*.formats.*' => [Rule::in(self::FORMATS)],
            'documents.*.frontBack' => ['required', 'boolean'],
            'documents.*.multiPage' => ['required', 'boolean'],
            'documents.*.expiry.required' => ['required', 'boolean'],
            'documents.*.expiry.minMonthsValid' => ['required', 'integer', 'min:0', 'max:24'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public static function messages(): array
    {
        return [
            'documents.*.id.regex' => 'Use lower-case letters, numbers and underscores for the document key.',
            'documents.*.id.not_in' => 'The National ID and supporting documents are handled for everyone.',
            'documents.*.condition.required_if' => 'Choose when this document is required.',
            'documents.*.maxSizeMb.max' => 'Files can be up to '.self::MAX_SIZE_MB.' MB.',
            'documents.*.formats.required' => 'Accept at least one file format.',
        ];
    }

    /** Publishes a new version. Earlier versions, and applications sent under them, stay as they were. */
    public function publish(Category $category, array $documents, string $reason, User $admin): DocumentRequirementVersion
    {
        $documents = array_map([$this, 'normalise'], array_values($documents));

        return DB::transaction(function () use ($category, $documents, $reason, $admin) {
            // One publisher at a time per profession, so version numbers don't clash
            Category::whereKey($category->id)->lockForUpdate()->first();
            $current = $this->current($category->id);

            if ($current && $current->documents == $documents) {
                throw new BusinessRuleException('requirements_unchanged', 'Nothing has changed since version '.$current->version.'.', 422);
            }

            $version = new DocumentRequirementVersion();
            $version->forceFill([
                'category_id' => $category->id,
                'version' => $current ? $current->version + 1 : 1,
                'documents' => $documents,
                'note' => $reason,
                'published_by' => $admin->getKey(),
                'published_at' => now(),
            ])->save();

            $this->audit->record('verification.requirements_published', $version,
                ['version' => $current ? $current->version : null],
                ['profession' => $category->slug, 'version' => $version->version, 'documents' => count($documents)],
                $reason, $admin);

            return $version;
        });
    }

    /** Only the known fields, in a fixed shape */
    private function normalise(array $d): array
    {
        $conditional = $d['requirement'] === 'conditional';

        return [
            'id' => $d['id'],
            'type' => $d['type'] ?? $d['id'],
            'name' => trim($d['name']),
            'instructions' => isset($d['instructions']) ? trim($d['instructions']) : null,
            'formats' => array_values(array_intersect(self::FORMATS, $d['formats'])),
            'maxSizeMb' => (int) $d['maxSizeMb'],
            'count' => (int) $d['count'],
            'frontBack' => (bool) $d['frontBack'],
            'multiPage' => (bool) $d['multiPage'],
            'expiry' => ['required' => (bool) $d['expiry']['required'], 'minMonthsValid' => (int) $d['expiry']['minMonthsValid']],
            'requirement' => $d['requirement'],
            'condition' => $conditional ? $d['condition'] : null,
            'enabled' => (bool) $d['enabled'],
        ];
    }
}
