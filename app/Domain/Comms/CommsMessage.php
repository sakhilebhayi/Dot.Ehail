<?php

namespace App\Domain\Comms;

use App\Models\User;

/**
 * One notification for one person, in plain language, before it is written anywhere.
 *
 * eventKey identifies the occurrence ("ride:12:driver_assigned:0"): the same key for the same
 * person is written once, however often the event arrives. collapseKey groups updates about the
 * same thing (one ride's progress), so a phone shows the latest and an older queued push that a
 * newer one overtook is not sent. replaceUnread removes the person's unread in-app items with the
 * same collapse key first (ride offers: only the live one matters).
 */
final readonly class CommsMessage
{
    /**
     * @param  array<string, scalar|null>  $data  extra keys for the in-app record (ids only)
     */
    public function __construct(
        public User $recipient,
        public string $type,
        public string $eventKey,
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $collapseKey = null,
        public ?int $actorId = null,
        public bool $replaceUnread = false,
        public array $data = [],
    ) {}

    public function actorIsRecipient(): bool
    {
        return $this->actorId !== null && $this->actorId === $this->recipient->id;
    }
}
