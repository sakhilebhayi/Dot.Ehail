<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Money held (reserved) and spent against one limit in one period: a traveller's monthly allowance
 * (scope member), a department's monthly budget (scope department) or the account's credit (scope
 * credit, period "open", reservations only: the spent part is the ledger's receivable).
 * Changed only with conditional single-statement updates (SpendLimits), so concurrent requests can
 * never push a limit over.
 */
class BusinessSpendCounter extends Model
{
    public const MEMBER = 'member';

    public const DEPARTMENT = 'department';

    public const CREDIT = 'credit';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reserved_minor' => 'integer', 'spent_minor' => 'integer'];
    }
}
