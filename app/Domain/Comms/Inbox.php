<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;

/**
 * The person's real in-app notifications, newest first, each with what happened to its other
 * copies (push / email / SMS) as recorded. Nothing here is invented: an empty inbox is empty.
 */
final class Inbox
{
    public function __construct(private readonly CommsCategories $categories) {}

    public function page(User $user, int $perPage = 20, bool $unreadOnly = false): CursorPaginator
    {
        $query = $user->notifications()->getQuery()->reorder()->orderByDesc('created_at')->orderByDesc('id');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query->cursorPaginate($perPage);
    }

    /**
     * Unread things needing the person's attention (D-1909): every unread notification about one ride counts once
     * (they share the collapse key "ride-<id>": searching, assigned, arriving, arrived, started, completed), so six
     * trips are six, not thirty-six. Notifications without a collapse key count one each.
     */
    public function unreadCount(User $user): int
    {
        return self::countFor($user);
    }

    public static function countFor(User $user): int
    {
        $keys = [];
        $loose = 0;

        $user->unreadNotifications()->getQuery()->reorder()->select(['id', 'data'])->orderByDesc('created_at')->limit(1000)
            ->each(function (DatabaseNotification $n) use (&$keys, &$loose): void {
                $key = ((array) $n->data)['collapse_key'] ?? null;
                is_string($key) && $key !== '' ? $keys[$key] = true : $loose++;
            });

        return $loose + count($keys);
    }

    /** The badge text: the count, capped at "9+". */
    public static function badge(int $count): ?string
    {
        return $count <= 0 ? null : ($count > 9 ? '9+' : (string) $count);
    }

    /**
     * The person opened this ride's page: its progress notifications are seen (marked read). Returns how many.
     */
    public static function markRideSeen(User $user, int $rideId): int
    {
        $ids = $user->unreadNotifications()->getQuery()->reorder()->select(['id', 'data'])->limit(200)->get()
            ->filter(fn (DatabaseNotification $n) => (((array) $n->data)['collapse_key'] ?? null) === 'ride-'.$rideId)
            ->pluck('id');

        return $ids->isEmpty() ? 0 : DatabaseNotification::query()->whereIn('id', $ids)->update(['read_at' => now()]);
    }

    /**
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<array<string, mixed>>
     */
    public function present(User $user, iterable $notifications): array
    {
        $list = collect($notifications);
        $deliveries = NotificationDelivery::where('user_id', $user->id)
            ->whereIn('notification_id', $list->pluck('id')->all())
            ->where('channel', '!=', NotificationDelivery::IN_APP)
            ->orderBy('channel')
            ->get()
            ->groupBy('notification_id');

        return $list->map(function (DatabaseNotification $n) use ($deliveries) {
            $data = (array) $n->data;
            $type = (string) ($data['type'] ?? 'general');
            $category = (string) ($data['category'] ?? $this->categories->categoryFor($type));
            $expiresAt = isset($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : null;

            return [
                'id' => $n->id,
                'type' => $type,
                'category' => $category,
                'category_label' => $this->categories->label($category),
                'critical' => $this->categories->isCritical($category),
                'title' => (string) ($data['title'] ?? 'Notification'),
                'message' => (string) ($data['message'] ?? ''),
                'url' => self::localUrl(isset($data['url']) ? (string) $data['url'] : null),
                'read' => $n->read_at !== null,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
                'expired' => $expiresAt !== null && $expiresAt->isPast(),
                'deliveries' => ($deliveries[$n->id] ?? collect())->map(fn (NotificationDelivery $d) => $d->toPublicArray())->values()->all(),
            ];
        })->values()->all();
    }

    /** Links only to this site: a relative path, or an absolute URL on the app's own host. */
    public static function localUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $requestHost = request()?->getHost();

        if ($host !== null && ($host === $appHost || $host === $requestHost)) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
            $query = parse_url($url, PHP_URL_QUERY);

            return $path.($query ? '?'.$query : '');
        }

        return null;
    }
}
