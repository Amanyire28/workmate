<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLogger
{
    /** Values under keys containing any of these are never stored. */
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'api_key', 'pin', 'otp'];

    /** @var Request */
    private $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Record an action. The actor defaults to the signed-in user; it is null
     * for system and command-line actions.
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        ?User $actor = null
    ): AuditLog {
        $actor = $actor ?: Auth::user();

        return AuditLog::create([
            'actor_id' => $actor ? $actor->getKey() : null,
            'action' => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject ? $subject->getKey() : null,
            'before' => $before ? $this->redact($before) : null,
            'after' => $after ? $this->redact($after) : null,
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
        ]);
    }

    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && Str::contains(strtolower($key), self::SENSITIVE_KEYS)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
