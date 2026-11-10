<?php

namespace App\Domain\Ops\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Audit rows written by the operations centre. Actions (role changes, suspensions, zone edits, exports) are
 * recorded every time; views of sensitive records (a ride, a driver, an incident, a case) are recorded once per
 * operator and record per 10 minutes, so the "who viewed what" trail stays readable while a page polls or reloads.
 */
class OpsAudit
{
    public const VIEW_DEDUPE_SECONDS = 600;

    /** @param array<string, mixed> $meta */
    public function action(string $action, User $actor, Model $subject, array $meta = []): AuditLog
    {
        return AuditLog::record($action, $actor, $subject, $meta);
    }

    /** @param array<string, mixed> $meta */
    public function viewed(string $what, User $actor, Model $subject, array $meta = []): void
    {
        $key = sprintf('ops-viewed:%d:%s:%s', $actor->id, $subject->getMorphClass(), (string) $subject->getKey());

        if (! Cache::add($key, 1, self::VIEW_DEDUPE_SECONDS)) {
            return;
        }

        AuditLog::record('ops.viewed.'.$what, $actor, $subject, $meta);
    }
}
