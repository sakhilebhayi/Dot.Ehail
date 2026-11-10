<?php

namespace App\Domain\Ledger;

use App\Domain\Ledger\Models\FinanceIssue;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Opens and resolves items on the finance exceptions list. Raising the same problem twice (same
 * dedupe key) keeps one open item.
 *
 * @phpstan-type IssueAttrs array{severity?: string, subject_type?: ?string, subject_id?: ?int, ride_id?: ?int, amount_minor?: ?int, currency?: ?string, details?: array<string, mixed>}
 */
class FinanceIssues
{
    /**
     * @param  IssueAttrs  $attributes
     */
    public function raise(string $kind, string $dedupeKey, string $summary, array $attributes = []): FinanceIssue
    {
        $existing = FinanceIssue::where('dedupe_key', $dedupeKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return \Illuminate\Support\Facades\DB::transaction(fn () => FinanceIssue::create(array_merge([
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'summary' => mb_substr($summary, 0, 255),
                'severity' => 'warning',
            ], $attributes)));
        } catch (UniqueConstraintViolationException) {
            return FinanceIssue::where('dedupe_key', $dedupeKey)->firstOrFail();
        }
    }

    public function resolve(FinanceIssue $issue, User $operator, string $note): FinanceIssue
    {
        $issue->forceFill([
            'status' => 'resolved',
            'resolved_by' => $operator->id,
            'resolved_at' => now(),
            'resolution_note' => mb_substr($note, 0, 500),
        ])->save();

        AuditLog::record('money.issue_resolved', $operator, $issue, ['kind' => $issue->kind, 'note' => $note]);

        return $issue;
    }
}
