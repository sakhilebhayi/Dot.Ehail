<?php

namespace App\Domain\Business\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A department or cost centre; an optional monthly budget is enforced at ride request time. */
class BusinessDepartment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monthly_budget_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class, 'business_account_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BusinessMember::class, 'department_id');
    }

    public function label(): string
    {
        return $this->cost_centre ? "{$this->name} ({$this->cost_centre})" : $this->name;
    }
}
