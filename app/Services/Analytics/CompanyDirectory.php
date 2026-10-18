<?php

namespace App\Services\Analytics;

use App\Models\Team;
use App\Models\User;

/**
 * Which InfoDot company a user's data belongs to, for Dot.Analytics.
 *
 * The same rule the Dot.Notify client uses for its recipients: a user's company is their CURRENT
 * team's infodot_team_uuid, or, when that team has none (or the user has no current team), their
 * personal team's. A user with neither is in no company and nothing of theirs is ever published.
 * Read only: nothing here switches a user's current team.
 */
class CompanyDirectory
{
    /**
     * Group users by company.
     *
     * @param  array<int, int|string>  $userIds
     * @return array<string, list<int>> company uuid => user ids, sorted by uuid
     */
    public function group(array $userIds): array
    {
        $groups = [];
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        foreach (array_chunk($ids, 500) as $chunk) {
            $users = User::query()->whereIn('id', $chunk)->get(['id', 'current_team_id']);

            $currentUuids = Team::query()
                ->whereIn('id', $users->pluck('current_team_id')->filter()->unique()->values())
                ->pluck('infodot_team_uuid', 'id');

            $personalUuids = Team::query()
                ->whereIn('user_id', $chunk)
                ->where('personal_team', true)
                ->orderBy('id')
                ->get(['user_id', 'infodot_team_uuid'])
                ->unique('user_id')
                ->pluck('infodot_team_uuid', 'user_id');

            foreach ($users as $user) {
                $uuid = $user->current_team_id ? ($currentUuids[$user->current_team_id] ?? null) : null;

                if (! filled($uuid)) {
                    $uuid = $personalUuids[$user->id] ?? null;
                }

                if (filled($uuid)) {
                    $groups[(string) $uuid][] = (int) $user->id;
                }
            }
        }

        ksort($groups);

        return array_map(fn (array $members) => array_values(array_unique($members)), $groups);
    }
}
