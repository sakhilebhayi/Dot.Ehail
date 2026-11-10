<?php

namespace App\Domain\Safety\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One notification an incident caused, with its real outcome: pending, sent, failed, skipped
 * (with why), not_delivered (accepted by a mailer that only logs) or queued (handed to a queue whose delivery this app cannot see, e.g. Dot.Notify).
 * The "what we did" payload is built only from these rows.
 */
class IncidentNotification extends Model
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const QUEUED = 'queued';

    public const PENDING = 'pending';

    /** The call succeeded but the configured transport does not deliver (mailer log/array). */
    public const NOT_DELIVERED = 'not_delivered';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'safety_incident_id');
    }

    /**
     * The recipient label as people read it. Rows written before R-46 polish say "7 operations team member(s)";
     * the count decides the word now (D-2208).
     */
    public function displayLabel(): string
    {
        return (string) preg_replace_callback('/\b(\d+) (.+?) member\(s\)/', fn ($m) => $m[1].' '.$m[2].' '.((int) $m[1] === 1 ? 'member' : 'members'), (string) $this->recipient_label);
    }
}
