<?php

namespace App\Domain\Comms\Models;

use App\Domain\Comms\DeliveryLabels;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One copy of one event for one person on one channel, with its real outcome. Unique per
 * (event_key, user_id, channel), so a retried or doubled event never makes a second copy. Rows in
 * `queued` are the work queue for DeliveryWorker.
 */
class NotificationDelivery extends Model
{
    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const IN_APP = 'in_app';

    public const PUSH = 'push';

    public const EMAIL = 'email';

    public const SMS = 'sms';

    protected $guarded = ['id'];

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'claimed_until' => 'datetime',
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'channel' => $this->channel,
            'status' => $this->status,
            'reason' => $this->reason,
            'label' => DeliveryLabels::for($this),
            'attempts' => $this->attempts,
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
