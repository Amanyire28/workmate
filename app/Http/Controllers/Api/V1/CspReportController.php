<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where browsers report what the Content Security Policy would block
 * (config/security.php, report-only at first). Each report is logged with
 * only what's needed to fix the policy: the directive, the blocked origin
 * and the page's path. Query strings are dropped, since a page or video
 * address can carry a reset token or a signature.
 */
class CspReportController extends Controller
{
    private const MAX_BYTES = 8192;

    public function store(Request $request): Response
    {
        $raw = (string) $request->getContent();
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) {
            return response('', 204);
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return response('', 204);
        }
        // Older format: {"csp-report": {...}}; newer Reporting API: [{"type": "csp-violation", "body": {...}}]
        $reports = isset($data['csp-report']) ? [$data['csp-report']] : array_map(function ($r) {
            return is_array($r) ? ($r['body'] ?? []) : [];
        }, array_slice(array_values($data), 0, 5));

        foreach ($reports as $r) {
            if (! is_array($r)) {
                continue;
            }
            Log::warning('Content Security Policy report', [
                'directive' => self::text($r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? null),
                'blocked' => self::where($r['blocked-uri'] ?? $r['blockedURL'] ?? null),
                'page' => self::path($r['document-uri'] ?? $r['documentURL'] ?? null),
                'source' => self::where($r['source-file'] ?? $r['sourceFile'] ?? null),
                'line' => isset($r['line-number']) ? (int) $r['line-number'] : (isset($r['lineNumber']) ? (int) $r['lineNumber'] : null),
                'mode' => self::text($r['disposition'] ?? null),
            ]);
        }

        return response('', 204);
    }

    private static function text($value): ?string
    {
        return is_string($value) ? substr(preg_replace('/[^\w\-\s\'.:]/', '', $value), 0, 80) : null;
    }

    /** "inline", "eval", "data", "blob", or an address's origin and path (never its query) */
    private static function where($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $parts = parse_url($value);
        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            return substr(preg_replace('/[^\w\-]/', '', $value), 0, 20);
        }

        return substr($parts['scheme'].'://'.$parts['host'].($parts['path'] ?? ''), 0, 160);
    }

    private static function path($value): ?string
    {
        return is_string($value) ? substr((string) (parse_url($value, PHP_URL_PATH) ?? ''), 0, 120) : null;
    }
}
