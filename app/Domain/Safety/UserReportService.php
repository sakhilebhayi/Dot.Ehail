<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Models\UserReport;
use App\Domain\Safety\Notifications\SafetyUpdateNotification;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Private reports about the other party of a ride (separate from the public rating, never shown
 * to the reported person), and system flags for operations. Reviewing a report records an
 * outcome; suspending an account is a separate, deliberate operator action and never automatic.
 */
class UserReportService
{
    public function report(Ride $ride, User $reporter, string $category, ?string $description): UserReport
    {
        $role = RideSafety::roleOf($ride, $reporter);
        $other = RideSafety::otherParty($ride, $reporter);

        if ($role === null || $other === null) {
            throw ValidationException::withMessages(['ride' => 'You can only report the other person on your own ride.']);
        }

        if (! in_array($category, UserReport::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Choose a category from the list.']);
        }

        try {
            return UserReport::create([
                'reporter_id' => $reporter->id,
                'reported_user_id' => $other,
                'ride_id' => $ride->id,
                'reporter_role' => $role,
                'category' => $category,
                'description' => $description !== null ? mb_substr(trim($description), 0, 2000) : null,
                'status' => 'open',
                // One report per category per ride per reporter.
                'dedupe_key' => "r:{$ride->id}:{$reporter->id}:{$category}",
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['category' => 'You already reported this on this ride. Operations will review it.']);
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function flag(User $subject, string $role, string $category, string $description, array $evidence, string $dedupeKey): ?UserReport
    {
        try {
            return UserReport::create([
                'reporter_id' => null,
                'reported_user_id' => $subject->id,
                'reporter_role' => 'system',
                'category' => $category,
                'description' => $description,
                'status' => 'open',
                'evidence' => $evidence + ['subject_role' => $role],
                'dedupe_key' => $dedupeKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @param  'reviewing'|'actioned'|'dismissed'  $status
     */
    public function review(UserReport $report, User $operator, string $status, ?string $note): UserReport
    {
        if (! in_array($status, ['reviewing', 'actioned', 'dismissed'], true)) {
            throw new SafetyActionRefused('Unknown review outcome.');
        }

        if (in_array($report->status, ['actioned', 'dismissed'], true)) {
            throw new SafetyActionRefused('This report has already been decided.');
        }

        $updated = DB::transaction(function () use ($report, $operator, $status, $note) {
            $report->forceFill(['status' => $status, 'reviewed_by' => $operator->id, 'reviewed_at' => now(), 'outcome_note' => $note])->save();
            AuditLog::record('user_report.'.$status, $operator, $report, ['category' => $report->category]);

            return $report;
        });

        if ($status !== 'reviewing' && $report->reporter_id !== null) {
            // The reporter learns it was reviewed, not what happened to the other person.
            $report->reporter?->notify(new SafetyUpdateNotification('user_report_reviewed', 'Your report was reviewed', 'Thank you. Operations reviewed your report about a recent ride.', ['report_id' => $report->id]));
        }

        return $updated;
    }
}
