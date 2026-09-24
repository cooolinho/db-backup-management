<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records one row per user action or job outcome. $subject can be an
 * Eloquent model (Backup, Restore), a plain identifier (an archive
 * database name), or omitted entirely (e.g. auth.login).
 */
class Audit
{
    public static function record(
        string $action,
        ?string $description = null,
        Model|string|null $subject = null,
        array $properties = [],
        ?int $userId = null,
    ): AuditLog {
        [$subjectType, $subjectId] = self::describeSubject($subject);

        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'properties' => $properties,
            'ip' => app()->runningInConsole() ? null : request()?->ip(),
        ]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function describeSubject(Model|string|null $subject): array
    {
        return match (true) {
            $subject instanceof Model => [$subject::class, (string) $subject->getKey()],
            is_string($subject) => [null, $subject],
            default => [null, null],
        };
    }
}
